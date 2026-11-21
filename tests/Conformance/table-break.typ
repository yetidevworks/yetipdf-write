#set page(width: 300pt, height: 200pt, margin: 10pt)
#set text(font: "Figtree", size: 9pt)
#set par(spacing: 6pt, leading: 4pt)
#table(columns: (50pt, 1fr, 40pt), inset: 5pt, stroke: 0.5pt, fill: (x, y) => if y == 0 { luma(90%) },
  table.header([Item], [Description], [Qty]),
  [A], [Short row], [1],
  [B], [Short row], [2],
  [C], [Short row], [3],
  [D], [Short row], [4],
  [E], [This row has a long description that runs over several lines so that the row cannot fit on the first page and has to break across the page boundary. It keeps going with more words to fill another page, which shows how insets and strokes are drawn on each piece of the row. Even more text follows here, line after line, until the row needs a third region to finish its content properly. And a final sentence.], [5],
  [F], [After], [6],
)
