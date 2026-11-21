#set page(width: 260pt, height: 300pt, margin: 10pt)
#set text(font: "DejaVu Sans", size: 12pt)
#set par(leading: 4pt, spacing: 6pt)
#block(width: 240pt)[Hello אבג #box[XY] דהו world]
#block(width: 240pt)[ABC #text(dir: rtl)[one (two) 123] DEF]
#block(width: 240pt)[ABC #text(dir: ltr)[אבג 123 דהו] DEF]
#block(width: 240pt)[‮office‬ and more]
#[#set text(lang: "he")
#block(width: 240pt)[אבג #link("https://example.com/a")[דהו English זחט] מנע]]
#block(width: 240pt)[á̱ ó̱ x]
