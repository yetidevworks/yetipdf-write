#set page(width: 200pt, height: 100pt, margin: 10pt, footer: context [#counter(page).display() / #counter(page).final().first()])
#set text(font: "Figtree", size: 10pt)
Page #context counter(page).display() of #context counter(page).final().first().
#pagebreak()
Now on #context counter(page).display().
#pagebreak()
Last page, #context counter(page).display() of #context counter(page).final().first().