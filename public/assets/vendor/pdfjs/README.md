# pdf.js (mitgeliefert)

Version 4.10.38, Apache-2.0, https://github.com/mozilla/pdf.js

Wird für die Anzeige der Grundriss-PDFs im Browser benutzt. Dadurch braucht der
Server keine PDF-Werkzeuge (poppler, ImageMagick, Ghostscript) – das PDF wird
ausschließlich im Browser gerendert, die Wandanschlussdosen liegen als Overlay
in Prozentkoordinaten darüber.

Die Dateien liegen bewusst unter `.js`, obwohl es ES-Module sind: ein Modul
erkennt der Browser am MIME-Typ, nicht an der Endung – und `.mjs` steht in
älteren `mime.types` von nginx nicht drin. Dann kommt
`application/octet-stream` zurück, der Browser lehnt das Modul ab und der
Grundriss bleibt leer. `.js` kennt jede nginx-Fassung.

Aktualisieren:

    npm pack pdfjs-dist@<version>
    tar xzf pdfjs-dist-<version>.tgz
    cp package/build/pdf.min.mjs public/assets/vendor/pdfjs/pdf.min.js
    cp package/build/pdf.worker.min.mjs public/assets/vendor/pdfjs/pdf.worker.min.js
