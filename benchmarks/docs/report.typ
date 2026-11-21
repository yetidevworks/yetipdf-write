// The long benchmark report in Typst (benchmarks/docs/report.json, made up by tools/bench-docs.php).
#let d = json("/benchmarks/docs/report.json")
#set document(title: d.title)
#set page(paper: "a4", margin: 56pt)
#set text(font: "Figtree", size: 10pt, fill: rgb("#14161b"), lang: "en", hyphenate: true)
#set par(leading: 0.55em, spacing: 0.8em, justify: true)
#block(spacing: 6pt, text(size: 22pt, weight: "bold", d.title))
#text(size: 11pt, fill: rgb("#4b5059"), d.subtitle)
#for s in d.sections {
  block(above: 18pt, below: 8pt, text(size: 14pt, weight: "semibold", s.title))
  for p in s.paragraphs { par(p) }
  if s.table != none {
    table(columns: (1fr, 1fr, 1fr, 1fr), inset: (x: 6pt, y: 4pt), stroke: (x, y) => if y == s.table.rows.len() { (bottom: 0.4pt + rgb("#c9ccd3")) },
      align: (x, y) => if x > 0 { right } else { left },
      table.header(..s.table.head.map(h => text(weight: "semibold", h))),
      ..s.table.rows.flatten(),
    )
  }
}
