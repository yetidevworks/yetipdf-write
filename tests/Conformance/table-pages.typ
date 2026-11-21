#set page(width: 300pt, height: 300pt, margin: 10pt)
#set text(font: "Figtree", size: 10pt)
#set par(spacing: 6pt, leading: 4pt)
#set page(height: 160pt)
#table(columns: (1fr, 50pt), inset: 4pt,
  table.header([Item], [Qty]),
  ..range(1, 16).map(i => ([Row #i], [#i])).flatten())