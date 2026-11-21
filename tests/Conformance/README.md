# Conformance fixtures

Each fixture is a pair: `<name>.typ` (the reference, rendered by Typst) and `<name>.xml` (the same design in YetiPDF markup). `php tools/conformance.php` compiles every `.typ` with the repo's fonts and records each word's position in `<name>.json`; `ConformanceTest` renders the `.xml` and checks every word lands within 0.1pt of Typst's.

`invoice.json` has no `.typ` here: it was recorded from ChronoSlice's own invoice template (`crates/chronoslice/src/pdf/invoice.typ`, as of ChronoSlice commit 9438c44) rendering the data in `examples/invoice/invoice.json` without the logo and chart (which that template doesn't have), and `invoice.xml` is `examples/invoice/invoice.xml.twig` rendered with the same data.
