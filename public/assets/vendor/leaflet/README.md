# Leaflet (mitgeliefert)

Version 1.9.4, BSD-2-Clause, https://github.com/Leaflet/Leaflet

Trägt die Grundrissansicht (`/grundriss`) und die AP-Standorte
(`/wlan/standorte`). Benutzt wird `L.CRS.Simple`: die Karte rechnet in
Plankoordinaten statt in Geokoordinaten, ein Marker sitzt damit auf einem
Punkt des Plans und bleibt dort, egal wie weit hinein- oder
herausgezoomt wird.

Gezeichnet wird in Kacheln – als Kachelpyramide vom Server (PNG/JPEG),
im Browser aus den Vektordaten (PDF, über pdf.js) oder als Overlay (SVG).

Aktualisieren:

    npm pack leaflet@<version>
    tar xzf leaflet-<version>.tgz
    cp package/dist/leaflet.js package/dist/leaflet.css public/assets/vendor/leaflet/
    cp -r package/dist/images public/assets/vendor/leaflet/images
    cp package/LICENSE public/assets/vendor/leaflet/LICENSE
