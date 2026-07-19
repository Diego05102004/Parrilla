(function () {
    "use strict";

    var els = {
        status: document.getElementById("status"),
        search: document.getElementById("search"),
        refresh: document.getElementById("refresh"),
        download: document.getElementById("download"),
        content: document.getElementById("content"),
        poster: document.getElementById("poster"),
        subtitle: document.getElementById("poster-subtitle"),
        date: document.getElementById("poster-date"),
    };

    var plans = [];
    var total = 0;

    function setStatus(text) {
        els.status.textContent = text;
    }

    // Los logos se sirven por el proxy PHP para que html2canvas pueda exportar
    // la imagen sin problemas de CORS (canvas "contaminado").
    function proxied(url) {
        return "img.php?u=" + encodeURIComponent(url);
    }

    function formatDate(iso) {
        var d = iso ? new Date(iso) : new Date();
        try {
            return d.toLocaleDateString("es-VE", {
                day: "2-digit", month: "long", year: "numeric",
            });
        } catch (e) {
            return d.toISOString().slice(0, 10);
        }
    }

    function channelCard(ch) {
        var card = document.createElement("div");
        card.className = "channel";
        card.dataset.name = ch.name.toLowerCase();

        var img = document.createElement("img");
        img.className = "channel__logo";
        img.loading = "eager";
        img.alt = ch.name;
        img.src = ch.logo ? proxied(ch.logo) : "";
        img.onerror = function () { img.style.visibility = "hidden"; };

        var name = document.createElement("div");
        name.className = "channel__name";
        name.textContent = ch.name;

        card.appendChild(img);
        card.appendChild(name);
        return card;
    }

    function gridOf(channels) {
        var grid = document.createElement("div");
        grid.className = "grid";
        channels.forEach(function (ch) { grid.appendChild(channelCard(ch)); });
        return grid;
    }

    function renderContent() {
        els.content.innerHTML = "";

        if (!plans.length) {
            var empty = document.createElement("div");
            empty.className = "empty";
            empty.textContent = "No hay canales para mostrar.";
            els.content.appendChild(empty);
            return;
        }

        plans.forEach(function (plan) {
            var section = document.createElement("section");
            section.className = "plan";

            var head = document.createElement("div");
            head.className = "plan__head";

            if (plan.logo) {
                var plogo = document.createElement("img");
                plogo.className = "plan__logo";
                plogo.alt = plan.name;
                plogo.src = proxied(plan.logo);
                plogo.onerror = function () { this.style.display = "none"; };
                head.appendChild(plogo);
            }

            var pname = document.createElement("span");
            pname.className = "plan__name";
            pname.textContent = plan.name;
            head.appendChild(pname);

            var pcount = document.createElement("span");
            pcount.className = "plan__count";
            pcount.textContent = plan.count + (plan.count === 1 ? " canal" : " canales");
            head.appendChild(pcount);

            var dl = document.createElement("button");
            dl.type = "button";
            dl.className = "plan__dl no-capture";
            dl.textContent = "Descargar este plan";
            dl.title = "Descargar solo la imagen de " + plan.name;
            dl.addEventListener("click", function () { download(section, plan.name); });
            head.appendChild(dl);

            section.appendChild(head);

            if (plan.categories && plan.categories.length) {
                plan.categories.forEach(function (cat) {
                    var catHead = document.createElement("div");
                    catHead.className = "cat__head";
                    catHead.innerHTML = '<span class="cat__name"></span>' +
                        '<span class="cat__count"></span>';
                    catHead.querySelector(".cat__name").textContent = cat.name;
                    catHead.querySelector(".cat__count").textContent =
                        cat.channels.length + " canales";
                    section.appendChild(catHead);
                    section.appendChild(gridOf(cat.channels));
                });
            } else {
                section.appendChild(gridOf(plan.channels));
            }

            els.content.appendChild(section);
        });
    }

    function applySearch() {
        var q = els.search.value.trim().toLowerCase();
        var visible = 0;
        els.content.querySelectorAll(".channel").forEach(function (card) {
            var match = !q || card.dataset.name.indexOf(q) !== -1;
            card.classList.toggle("channel--hidden", !match);
            if (match) visible++;
        });
        setStatus(q ? (visible + " de " + total + " canales") : resumen());
    }

    function resumen() {
        return plans.length + " planes · " + total + " canales";
    }

    function load(refresh) {
        setStatus(refresh ? "Actualizando desde la web oficial…" : "Cargando canales…");
        els.download.disabled = true;

        var url = "api.php" + (refresh ? "?refresh=1" : "");
        fetch(url, { cache: "no-store" })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok || !data.plans) {
                    throw new Error(data.error || "Respuesta inválida del servidor.");
                }
                plans = data.plans;
                total = data.total || 0;
                els.subtitle.textContent = "Más de " + total + " canales full HD · " +
                    plans.length + " planes";
                els.date.textContent = "Actualizado: " + formatDate(data.generated_at);
                renderContent();
                applySearch();
                els.download.disabled = false;

                var extra = data.stale ? " (copia guardada)" : (data.cached ? " (cache)" : "");
                setStatus(resumen() + extra);
            })
            .catch(function (err) {
                setStatus("Error: " + err.message);
            });
    }

    function waitForImages() {
        var imgs = Array.prototype.slice.call(els.content.querySelectorAll("img"));
        return Promise.all(imgs.map(function (img) {
            if (img.complete) return Promise.resolve();
            return new Promise(function (resolve) {
                img.addEventListener("load", resolve, { once: true });
                img.addEventListener("error", resolve, { once: true });
            });
        }));
    }

    function slugify(text) {
        return (text || "")
            .toLowerCase()
            .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/^-+|-+$/g, "") || "plan";
    }

    // Si se pasa una sección de plan, exporta solo ese plan (imagen corta,
    // ideal para WhatsApp). Sin argumentos, exporta la grilla completa.
    function download(onlySection, planName) {
        if (els.search.value.trim()) {
            // Para la imagen exportamos siempre sin filtro de búsqueda.
            els.search.value = "";
            applySearch();
        }

        setStatus("Generando imagen…");
        els.download.disabled = true;

        // Modo exportación: agranda logos y nombres para que se lean bien
        // cuando la imagen se comparte (p. ej. por WhatsApp).
        els.poster.classList.add("poster--export");

        // Oculta los demás planes cuando se exporta uno solo.
        var hidden = [];
        if (onlySection) {
            els.content.querySelectorAll(".plan").forEach(function (sec) {
                if (sec !== onlySection) {
                    hidden.push([sec, sec.style.display]);
                    sec.style.display = "none";
                }
            });
        }

        function restore() {
            hidden.forEach(function (pair) { pair[0].style.display = pair[1]; });
        }

        waitForImages()
            .then(function () {
                return html2canvas(els.poster, {
                    backgroundColor: "#0d1430",
                    scale: 2,
                    useCORS: true,
                    logging: false,
                    ignoreElements: function (el) {
                        return el.classList && el.classList.contains("no-capture");
                    },
                });
            })
            .then(function (canvas) {
                var link = document.createElement("a");
                var stamp = new Date().toISOString().slice(0, 10);
                var mid = onlySection ? slugify(planName) + "-" : "";
                // JPG de alta calidad: mucho más liviano que PNG y apto para
                // enviar por WhatsApp sin perder legibilidad.
                link.download = "parrilla-thundernet-" + mid + stamp + ".jpg";
                link.href = canvas.toDataURL("image/jpeg", 0.92);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setStatus(resumen() +
                    (onlySection ? " · imagen de " + planName + " descargada"
                                 : " · imagen descargada"));
            })
            .catch(function (err) {
                setStatus("No se pudo generar la imagen: " + err.message);
            })
            .finally(function () {
                restore();
                els.poster.classList.remove("poster--export");
                els.download.disabled = false;
            });
    }

    els.search.addEventListener("input", applySearch);
    els.refresh.addEventListener("click", function () { load(true); });
    els.download.addEventListener("click", function () { download(); });

    load(false);
})();
