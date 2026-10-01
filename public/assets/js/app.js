/* Temps & Congés — Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021. */
/* Temps & Congés — JavaScript sans dépendance.
   Tout fonctionne aussi sans JavaScript (formulaires classiques) : ce fichier ajoute le confort
   (horloge, badge sans rechargement, aperçu des jours de congé, application de bureau). */
'use strict';

(() => {
    const base = document.querySelector('meta[name="base-url"]')?.content || '/';

    // ---------- Horloge en direct ----------
    const horloges = document.querySelectorAll('[data-horloge]');
    const formatHeure = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const tic = () => horloges.forEach((h) => { h.textContent = formatHeure.format(new Date()); });
    if (horloges.length) { tic(); setInterval(tic, 1000); }

    // ---------- Temps travaillé qui avance pendant la journée ----------
    const formatDuree = (s) => `${Math.floor(s / 3600)} h ${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}`;
    const compteur = document.querySelector('[data-compteur]');
    const chrono = { secondes: 0, enCours: false, depuis: Date.now() };
    const majCompteur = () => {
        if (!compteur) return;
        const ecoule = chrono.enCours ? Math.floor((Date.now() - chrono.depuis) / 1000) : 0;
        compteur.textContent = formatDuree(chrono.secondes + ecoule);
    };
    if (compteur) {
        chrono.secondes = Number(compteur.dataset.secondes) || 0;
        chrono.enCours = compteur.dataset.enCours === '1';
        setInterval(majCompteur, 10000);
    }

    // ---------- Badgeage sans rechargement de page ----------
    const zone = document.querySelector('[data-zone-badge]');
    const annonce = document.querySelector('[data-annonce]');
    const annoncer = (message, type) => {
        if (!annonce) return;
        annonce.textContent = message;
        annonce.className = `annonce ${type}`;
    };

    const creerFormulaireBadge = (action, modele) => {
        const form = document.createElement('form');
        form.method = 'post';
        form.action = modele.action;
        form.dataset.badge = '';
        const csrf = modele.querySelector('input[name="_csrf"]').cloneNode();
        const type = Object.assign(document.createElement('input'), { type: 'hidden', name: 'type', value: action.type });
        const bouton = Object.assign(document.createElement('button'), {
            type: 'submit', className: `bouton bouton-badge action-${action.type}`, textContent: action.libelle,
        });
        form.append(csrf, type, bouton);
        return form;
    };

    const majZone = (data, modele) => {
        const etat = zone.querySelector('[data-etat]');
        etat.replaceChildren(Object.assign(document.createElement('span'), {
            className: `pastille etat-${data.etat}`, textContent: data.etat_libelle,
        }));
        zone.querySelector('[data-actions]').replaceChildren(...data.actions.map((a) => creerFormulaireBadge(a, modele)));
        const liste = zone.querySelector('[data-chronologie]');
        liste.replaceChildren(...data.evenements.map((ev) => {
            const li = document.createElement('li');
            li.append(Object.assign(document.createElement('time'), { textContent: ev.heure }), ` ${ev.libelle}`);
            return li;
        }));
        chrono.secondes = data.secondes;
        chrono.enCours = data.en_cours;
        chrono.depuis = Date.now();
        majCompteur();
    };

    zone?.addEventListener('submit', async (evenement) => {
        const form = evenement.target.closest('form[data-badge]');
        if (!form) return;
        evenement.preventDefault();
        const bouton = form.querySelector('button');
        bouton.disabled = true;
        try {
            const reponse = await fetch(form.action, {
                method: 'POST', body: new FormData(form), credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await reponse.json();
            annoncer(data.message, data.ok ? 'succes' : 'erreur');
            if (data.etat) majZone(data, form);
            if (reponse.status === 401) window.location.href = `${base}connexion.php`;
        } catch {
            annoncer("Connexion impossible : le badge n'a pas été enregistré. Réessayez.", 'erreur');
        } finally {
            bouton.disabled = false;
        }
    });

    // ---------- Aperçu des jours ouvrés d'une demande de congé ----------
    const formConge = document.querySelector('[data-form-conge]');
    if (formConge) {
        const apercu = formConge.querySelector('[data-apercu-jours]');
        const debut = formConge.elements.date_debut;
        const fin = formConge.elements.date_fin;
        let controleur;
        const maj = async () => {
            if (debut.value) fin.min = debut.value;
            if (!debut.value || !fin.value) { apercu.textContent = ''; return; }
            controleur?.abort();
            controleur = new AbortController();
            const params = new URLSearchParams({
                debut: debut.value, fin: fin.value,
                debut_apres_midi: formConge.elements.debut_apres_midi.checked ? '1' : '0',
                fin_midi: formConge.elements.fin_midi.checked ? '1' : '0',
            });
            try {
                const reponse = await fetch(`${base}api/jours.php?${params}`, {
                    headers: { Accept: 'application/json' }, signal: controleur.signal,
                });
                const data = await reponse.json();
                apercu.className = data.ok ? 'apercu' : 'apercu erreur';
                if (!data.ok) { apercu.textContent = data.message; return; }
                const feries = data.feries.length ? ` · férié(s) exclu(s) : ${data.feries.map((f) => f.nom).join(', ')}` : '';
                apercu.textContent = `${data.jours_texte} jour(s) ouvré(s) décompté(s)${feries}`;
            } catch (erreur) {
                if (erreur.name !== 'AbortError') apercu.textContent = '';
            }
        };
        formConge.addEventListener('change', maj);
        maj();
    }

    // ---------- Petits comportements ----------
    document.addEventListener('submit', (evenement) => {
        const message = evenement.target.dataset?.confirmer;
        if (message && !window.confirm(message)) evenement.preventDefault();
    });
    document.querySelectorAll('[data-soumettre-au-changement]').forEach((champ) => {
        champ.addEventListener('change', () => champ.form.submit());
    });

    // Borne : le message s'efface et le champ matricule reprend le focus pour la personne suivante
    if (document.querySelector('[data-borne]')) {
        const alerte = document.querySelector('.alerte');
        if (alerte) setTimeout(() => alerte.remove(), 6000);
        document.querySelector('[data-focus-borne]')?.focus();
    }

    // ---------- Application de bureau (PWA) ----------
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(`${base}sw.js`).catch(() => { /* site utilisable sans */ });
        });
    }
})();
