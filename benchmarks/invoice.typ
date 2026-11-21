// The example invoice in Typst, ported from examples/invoice/invoice.xml.twig so the benchmark
// compares the same document. Compile from the repo root:
//   typst compile --root . --font-path fonts --ignore-system-fonts benchmarks/invoice.typ out.pdf
#let d = json("/examples/invoice/invoice.json")

#let ink = rgb("#14161b")
#let ink2 = rgb("#4b5059")
#let ink3 = rgb("#7a7f89")
#let rule = rgb("#e3e5ea")
#let rule2 = rgb("#c9ccd3")
#let wash = rgb("#f5f6f8")
#let tones = (paid: rgb("#1d7044"), overdue: rgb("#b3261e"), void: ink2, draft: ink3)
#let tone(t) = if t == none { ink } else { tones.at(t) }
#let eyebrow(body, fill: ink3) = text(size: 6.6pt, weight: "semibold", tracking: 0.09em, fill: fill, upper(body))
#let lines(items) = items.join(linebreak())
// Text that shrinks to fit its width (YetiPDF Write's fit="shrink").
#let fit(size, body) = layout(avail => {
  let w = measure(text(size: size, body)).width
  text(size: if w > avail.width { size * (avail.width / w) * 0.98 } else { size }, body)
})

#set document(title: "Invoice " + d.number + " – " + d.client, author: d.brand.name)
#set text(font: "Figtree", size: 9pt, fill: ink, number-width: "tabular")
#set par(leading: 0.48em, spacing: 0.6em)
#set page(
  paper: if d.paper == "a4" { "a4" } else { "us-letter" },
  margin: (top: 44pt, x: 46pt, bottom: 60pt),
  header-ascent: 14pt,
  footer-descent: 14pt,
  header: context if here().page() > 1 {
    set text(size: 7.4pt, fill: ink3)
    grid(columns: (1fr, auto), align: bottom,
      text(weight: "semibold", fill: ink2, d.brand.name),
      align(right)[#text(weight: "medium", fill: ink2)[Invoice #d.number]#h(5pt)·#h(5pt)#d.client],
    )
    v(-2pt)
    line(length: 100%, stroke: 0.4pt + rule)
  },
  footer: context {
    set text(size: 6.8pt, fill: ink3)
    block(below: 5pt, line(length: 100%, stroke: 0.4pt + rule))
    grid(columns: (1fr, auto), column-gutter: 24pt,
      text(fill: ink2, lines(d.brand.note)),
      align(right)[
        #text(weight: "medium", fill: ink2)[Page #counter(page).display() of #counter(page).final().first()] \
        Generated #d.generated#h(4pt)·#h(4pt)#text(size: 5.8pt)[quillmoor.example]
      ],
    )
  },
)

// Letterhead
#grid(columns: (1fr, auto), column-gutter: 24pt, align: horizon,
  if d.brand.logo != none {
    grid(columns: (auto, auto), column-gutter: 10pt, align: horizon,
      image("/examples/invoice/" + d.brand.logo, height: 34pt),
      text(size: 19pt, weight: "bold", tracking: -0.015em, d.brand.name))
  } else { text(size: 21pt, weight: "bold", tracking: -0.015em, d.brand.name) },
  align(right, text(size: 7.6pt, fill: ink2, par(leading: 0.42em, lines(d.brand.details)))),
)
#v(10pt)
#grid(columns: (72pt, 1fr), rows: 2.2pt, align: horizon,
  line(length: 100%, stroke: 2.2pt + rgb(d.brand.accent)),
  line(length: 100%, stroke: 0.5pt + rule),
)

// Heading and bill-to
#v(20pt)
#grid(columns: (1fr, 200pt), column-gutter: 24pt,
  [
    #block(spacing: 0pt, eyebrow(fill: rgb(d.brand.accent_text))[Invoice])
    #v(8pt)
    #block(spacing: 0pt)[#text(size: 24pt, weight: "semibold", tracking: -0.015em, d.number)#if d.mark != none {
      let c = tones.at(d.mark.tone)
      h(12pt)
      box(baseline: -5pt, stroke: (paint: c, thickness: 1.3pt, dash: if d.mark.tone == "draft" { "dashed" } else { none }), radius: 3pt, inset: (x: 7pt, y: 4pt),
        text(size: 9pt, weight: "bold", tracking: 0.16em, fill: c, upper(d.mark.label)))
    }]
  ],
  [
    #block(spacing: 0pt, eyebrow[Bill to])
    #v(7pt)
    #block(spacing: 0pt)[
      #set par(leading: 0.46em)
      #text(size: 10pt, weight: "semibold", d.bill_to.first())#for l in d.bill_to.slice(1) [\ #text(size: 8.4pt, fill: ink2, l)]#if d.email != none [\ #text(size: 8.4pt, fill: ink3, d.email)]
    ]
  ],
)

// Facts strip
#v(16pt)
#block(stroke: 0.5pt + rule, radius: 9pt, inset: 2.5pt, block(fill: wash, radius: 7pt, inset: (x: 14pt, y: 10pt), width: 100%,
  grid(
    columns: d.facts.map(f => if f.strong { 1.3fr } else if f.label == "Period" { 1.55fr } else { 1fr }),
    ..d.facts.enumerate().map(((i, f)) => {
      let c = tone(f.tone)
      grid.cell(inset: (top: 0pt, right: 6pt, bottom: 0pt, left: if i == 0 { 0pt } else { 12pt }), stroke: (left: if i == 0 { none } else { 0.5pt + rule2 }))[
        #block(spacing: 0pt, eyebrow(fill: if f.strong and f.tone != none { c } else { ink3}, f.label))
        #v(8pt)
        #block(spacing: 0pt, text(weight: if f.strong { "semibold" } else { "medium" }, fill: if f.strong { c } else { ink }, fit(if f.strong { 17pt } else { 12.5pt }, f.value)))
        #v(5pt)
        #block(spacing: 0pt, text(size: 7pt, fill: if f.tone != none and not f.strong { c } else { ink3 }, if f.sub == "" { [~] } else { f.sub }))
      ]
    }),
  ),
))

// Chart: hours by week, with the legend beside the heading and the weeks under the columns
#if d.chart != none {
  let ch = d.chart
  v(18pt)
  grid(columns: (1fr, auto), align: bottom,
    [
      #block(spacing: 0pt, eyebrow(ch.title))
      #v(6pt)
      #block(spacing: 0pt, text(size: 11pt, weight: "semibold", ch.total))
    ],
    align(right, text(size: 7.4pt, fill: ink2, ch.legend.enumerate().map(((i, l)) => [#box(fill: rgb(l.color), radius: 1.5pt, width: 7pt, height: 7pt, baseline: -0.5pt)#h(4pt)#l.label#h(3pt)#text(fill: ink3, l.hours)#if i < ch.legend.len() - 1 { h(12pt) }]).join())),
  )
  v(8pt)
  block(spacing: 0pt, image("/examples/invoice/" + ch.src, width: 100%))
  v(5pt)
  grid(columns: ch.weeks.map(_ => 1fr),
    ..ch.weeks.map(w => align(center)[
      #block(spacing: 0pt, text(size: 7.6pt, weight: "medium", w.hours))
      #v(2pt)
      #block(spacing: 0pt, text(size: 6.6pt, fill: ink3, w.label))
    ]),
  )
}

// Lines
#v(20pt)
#let head(body, end: false) = table.cell(inset: (top: 2pt, x: 6pt, bottom: 5pt), stroke: (bottom: 0.7pt + rule2), align: if end { right } else { left }, eyebrow(body))
#table(
  columns: (1fr, 64pt, 74pt, 82pt),
  inset: (x: 6pt, y: 6.2pt),
  stroke: none,
  table.header(head[Description], head(end: true)[Qty], head(end: true)[Unit price], head(end: true)[Amount]),
  ..d.lines.enumerate().map(((i, l)) => (
    if i > 0 { (table.hline(stroke: 0.4pt + rule),) } else { () },
    [
      #block(spacing: 0pt, text(weight: "medium", l.description))
      #if l.detail != none and l.detail != "" { v(3.6pt); block(spacing: 0pt, text(size: 7.4pt, fill: ink3, l.detail)) }
    ],
    align(right, text(fill: ink2)[#l.qty#if l.unit != none and l.unit != "" { h(2.5pt); text(size: 7.6pt, fill: ink3, l.unit) }]),
    align(right, text(fill: ink2, l.price)),
    align(right, text(weight: "medium", l.amount)),
  )).flatten(),
  table.hline(stroke: 0.7pt + rule2),
  table.cell(colspan: 4, inset: (top: 12pt, x: 6pt, bottom: 0pt), grid(columns: (1fr, 238pt), column-gutter: 30pt,
    grid.cell(inset: (top: 4pt))[
      #if d.notes.len() > 0 [
        #block(spacing: 0pt, eyebrow[Notes])
        #v(7pt)
        #block(text(size: 8.2pt, fill: ink2, par(leading: 0.5em, lines(d.notes))))
      ]
    ],
    [
      #set text(size: 8.8pt)
      #table(columns: (1fr, auto), inset: (x: 0pt, y: 3.6pt), stroke: none,
        ..d.totals.enumerate().map(((i, t)) => {
          let total = t.kind == "total"
          let inset = if total { (top: 6pt, x: 0pt, bottom: 4pt) } else { (x: 0pt, y: 3.6pt) }
          let w = if total { "semibold" } else { "regular" }
          (
            if total and i > 0 { (table.hline(stroke: 0.8pt + ink),) } else { () },
            table.cell(inset: inset, text(weight: w, fill: if total { ink } else { ink2 }, t.label)),
            table.cell(inset: (..inset, left: 14pt), align: right, text(weight: w, t.value)),
          )
        }).flatten(),
      )
      #v(6pt)
      #let b = d.balance
      #let c = tone(b.tone)
      #block(stroke: 0.5pt + rule, radius: 8pt, inset: 2.5pt, block(fill: wash, radius: 6pt, inset: (x: 11pt, y: 9pt), width: 100%)[
        #grid(columns: (1fr, auto), column-gutter: 10pt, align: horizon,
          text(size: 9.6pt, weight: "semibold", fill: c, b.label),
          align(right, text(size: 16pt, weight: "bold", fill: c, b.value)),
        )
        #if b.sub != none and b.sub != "" [
          #v(3pt)
          #text(size: 7.2pt, fill: if b.tone == "overdue" { c } else { ink3 }, weight: if b.tone == "overdue" { "medium" } else { "regular" }, b.sub)
        ]
      ])
    ],
  )),
)
