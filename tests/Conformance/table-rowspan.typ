#set page(width: 300pt, height: 300pt, margin: 10pt)
#set text(font: "Figtree", size: 10pt)
#set par(spacing: 6pt, leading: 4pt)
#table(columns: (60pt, 1fr, 1fr), inset: 5pt, stroke: 0.5pt,
  table.cell(rowspan: 2)[Span#linebreak()two#linebreak()rows#linebreak()tall#linebreak()cell], [B1], [C1],
  [B2], [C2],
  table.cell(colspan: 2)[Wide cell], [C3])