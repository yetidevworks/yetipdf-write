#set page(width: 200pt, height: 120pt, margin: 10pt)
#set text(font: "Figtree", size: 10pt)
#set par(leading: 4pt, spacing: 0pt)
#table(columns: (1fr,1fr), inset: 2pt, stroke: 1pt,
 table.header([HEAD],[HEAD2]),
 table.cell(rowspan: 2)[#for i in range(1,25) { if i > 1 {linebreak()}; [LINE#if i < 10 { "0" + str(i) } else { str(i) }] }], [RIGHT1], [RIGHT2])
#block[AFTER]
