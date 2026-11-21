#set page(width: 300pt, height: 200pt, margin: 10pt)
#set text(font: "Figtree", size: 9pt)
#set par(spacing: 6pt, leading: 4pt)
#table(columns: (50pt, 1fr, 40pt), inset: 5pt, stroke: 0.5pt,
  table.header([Item], [Description], [Qty]),
  ..range(1, 6).map(i => ([R#i], [Short row], [#i])).flatten(),
  [E], [This medium row has six or so lines of text, which would fit on a fresh page but not in the space left on this one. It needs a few more words to reach six lines in total here. Then a few more lines again, so that only part of it can fit in the room left, with a last line here. And still more sentences are added to this cell so that it clearly cannot fit in the space left, but it would still fit on a fresh page.], [5],
  [F], [After], [6],
)
