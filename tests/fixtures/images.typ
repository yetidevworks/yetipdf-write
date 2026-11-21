#set page(width: 520pt, height: 360pt, margin: 20pt)
#set text(font: "Figtree", size: 8pt)
#grid(columns: (1fr,) * 4, column-gutter: 8pt, row-gutter: 8pt,
  ..("rgba.png", "rgb.png", "palette.png", "gray-alpha.png", "rgb16.png", "colorkey.png", "rgb.jpg", "cmyk.jpg").map(f =>
    block(fill: rgb("#f7d9e3"), inset: 6pt, width: 100%, { image("images/" + f, width: 100%, height: 70pt, fit: "contain"); v(3pt); f }))
)
