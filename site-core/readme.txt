=== Site Core ===
Contributors: dario
Requires at least: 6.4
Tested up to: 7.0.4
Requires PHP: 8.0
Stable tag: 2.17.0
License: GPLv2 or later

Native form engine for hand-built WordPress sites. No Elementor, no
WPForms/CF7/Gravity Forms , forms are always built against this plugin's
contract, on every surface (Gutenberg, custom theme, or plain PHP).

== Description ==

Site Core provides native forms, editable content, media folders and an optional hub-authorized MCP bridge.

Account-based MCP (2.10.0): one Streamable HTTP endpoint with native WordPress
Application Passwords or authorization-code OAuth/PKCE. Project Memory and
skills stay centralized in GestioneSiti. MCP is disabled by default. Activation
does not grant access. Select one or more accounts in the site's Hub MCP panel,
regardless of their WordPress role. The hub verifies each new request; an outage
blocks MCP only, never the public site. The legacy developer role is removed.

* `SCC_Form_Registry::register( $slug, $config )` , register a form and its field schema.
* Markup contract: `data-scc-form="<slug>"`, `wp_nonce_field('scc_form_<slug>', 'scc_nonce')`,
  a `.scc-honeypot`-wrapped hidden field, and a `scc_timestamp` hidden field ,
  use `scc_render_form_security_fields( $slug )` to output all three at once.
* Submissions are validated, rate-limited per IP (hashed, never stored raw),
  written to a dedicated `wp_scc_entries` table, and logged stage-by-stage to
  `wp_scc_logs` for debugging.
* `scc_form_submitted` action fires after a submission is fully processed ,
  the sanctioned extension point for anything else that needs to react to it.
* Admin: Form Configuration (transport, webhook, success behavior per form)
  and Entries (filterable list + per-entry log timeline).

Theme/design settings are NOT part of this plugin (v2.0.0+) , that's owned
by whichever design pipeline the site uses (Novamira DESIGN.md, or a
theme.json built by hand). Site Core stays scoped to forms only, so it's a
small, auditable dependency regardless of which build pipeline a given site
uses.

== Changelog ==

= 2.17.0 =
* Due attivatori MCP indipendenti nell'HUB: l'accesso Cliente (invariato) e il nuovo accesso Sviluppo, che governa un account dedicato ("growbe-developer") usato da OpenCode tramite il bridge. Spegnere l'accesso del cliente non interrompe più lo sviluppo e un sito nuovo non resta bloccato.
* Collegamento automatico di OpenCode: con l'accesso Sviluppo attivo nell'HUB, il battito porta il comando che prepara l'account, collega la memoria di progetto e manda il permesso monouso al bridge. Nessuna azione sul sito cliente.
* L'account sviluppatore non può fare il login dalla bacheca né reimpostare la password, non può essere modificato dagli altri utenti e non compare nell'elenco account del cliente.
* Il collegamento della memoria di progetto (bind) ora manda un JSON esplicito.

= 2.16.0 =
* Il form "Nuovo contenuto" nella bacheca Growbe ora mostra il controllo giusto per ogni campo (area di testo, editor, data, email, indirizzo web, telefono, colore, menu a tendina con scelte) invece di un solo campo di testo generico per tutto. Nessuna azione richiesta sui campi gia' esistenti: restano campi di testo finche' non gli si assegna un formato piu' preciso.

= 2.15.0 =
* Accesso MCP per sito, login automatico senza esclusioni di account.
* L'accesso MCP ora si abilita per l'intero sito, non piu' per singolo account: se attivo, qualunque amministratore WordPress puo' usarlo. Il login automatico dalla bacheca Growbe funziona per qualsiasi amministratore, incluso chi ha accesso MCP.

= 2.14.1 =
* Corretto un errore fatale nel battito quando un tema ha un aggiornamento disponibile (WP_Theme::cache_get() e privato, non richiamabile da site-core): ora la versione aggiornata del tema si legge dal transient pubblico update_themes.

= 2.12.3 =
* Stable general-purpose content abilities using post_type and taxonomy parameters; no generated tool names tied to site-specific CPTs.
* Declarative creation/update of SiteCore-owned CPTs, taxonomies and scalar fields, with ownership checks and revision conflicts. Existing external definitions are never overwritten.

= 2.11.0 =
* Native MCP adapters for WordPress content/media/taxonomies, CF7 forms, SiteCore custom forms, WooCommerce products/variations/order summaries and Polylang translations.
* Explicit write confirmation, atomic request receipts and native account permissions. MCP configuration moved under Site Core.
* WPML, payments/refunds, customer exports, arbitrary execution and custom form source-code changes are not supported.

= 2.10.2 =
* Fix early OAuth identity resolution with Polylang: defer REST URL resolution until WordPress rewrite initialization; preserve token route restrictions.

= 2.10.1 =
* Prompt di collegamento copiabili per Codex / ChatGPT desktop e ChatGPT web, con URL e account già compilati e nessuna password nel prompt.
* Configurazione locale HTTP nativa con Authorization da variabile d'ambiente; istruzioni OAuth separate per il collegamento web. Nessuna nuova ability WordPress o plugin in questa versione.

= 2.10.0 =
* Accessi MCP per singolo account gestiti esclusivamente dall'HUB, selezione multipla e interruttore generale.
* Nessun ruolo WordPress abilita automaticamente MCP. Revoca verificata a ogni richiesta, anche per OAuth e Application Password già emesse.
* Rimosso il ruolo Developer: gli account preesistenti conservano accesso amministrativo, senza autorizzazione MCP automatica.
* Sincronizzazione account indipendente da NovaMira e MCP. Permessi WordPress ordinari mantenuti.

= 2.8.2 =
* Nuova esperienza per le cartelle media: sidebar curata con ricerca e conteggi, creazione/rinomina/eliminazione direttamente nella Libreria, sottocartelle, filtro Non assegnati e drag-and-drop anche per selezioni multiple. I file restano nella Media Library e non vengono mai cancellati eliminando una cartella.

= 2.8.1 =
* Cambiata l'interfaccia delle cartelle media della 2.8.0 dopo prova dal vivo: via la tendina di filtro nella Griglia, al suo posto una sidebar ad albero sempre visibile a sinistra (stile file-manager), solo nella pagina Libreria a schermo intero. Il filtro server e il resto (vista Elenco, campo Cartella nel pannello dettagli) restano gli stessi.

= 2.8.0 =
* Novita': cartelle per la Libreria media (tassonomia gerarchica sugli allegati, filtro in Elenco e in Griglia, campo Cartella nel pannello dettagli). Le cartelle si gestiscono dallo schermo nativo dei termini di WordPress, linkato dal menu di Site Core.
* Novita': ruolo "developer" creato all'attivazione (stesse capacita' di un amministratore, piu' scc_developer) e assegnato a chi installa il plugin. Sui siti gia' attivi arriva con il primo aggiornamento, senza assegnarlo a nessuno. Prepara il terreno per la connessione MCP, non ancora costruita.

= 2.7.6 =
* Fix: scc_image()/[scc_image] accettano ora un attachment_id esplicito (piu' affidabile della ricerca per URL, che fallisce sulle dimensioni ridimensionate). Fix: l'attributo class e' sempre esplicito nel markup generato (anche vuoto), per parita' esatta con mama_img/wp_get_attachment_image.

= 2.7.5 =
* Parita' con mama_img/wp_get_attachment_image: dimensione di default 'large' (non piu' 'full') quando non specificata, e loading="lazy" esplicito quando l'immagine non e' eager. Prepara il retrofit sito-wide da mama_img a scc_image senza differenze visive.

= 2.7.4 =
* Novita': rotazione a 90 gradi e riempimento (object-fit: cover/contain/fill/none/scale-down) per le immagini editabili, applicati solo all'immagine e non al box che la contiene. Il tema resta al comando finche' non li imposti esplicitamente dall'editor.

= 2.7.3 =
* Fix/miglioria: le immagini editabili generate con scc_image() ora usano wp_get_attachment_image() quando l'allegato e' risolvibile (sostituito dalla Media Library o URL gia' in libreria), quindi width/height reali, srcset/sizes responsive e lazy-loading nativo come le immagini gestite direttamente dal tema. Nuovi parametri size e eager (per le immagini above-the-fold, es. hero).

= 2.7.2 =
* Fix: le immagini editabili (scc_image) ora accettano un attributo/parametro class passato al tema, e non aggiungono piu' nessuno style inline di dimensionamento sull'<img> - il markup e il comportamento CSS tornano identici a quelli del tema (wp_get_attachment_image), risolvendo la sparizione di immagini hero con CSS basato su class (object-fit/object-position).

= 2.7.1 =
* Corretto il pannello larghezza/altezza dei box di testo: il campo non si
  lasciava scrivere (il click non arrivava al campo) e compariva nel punto
  sbagliato la prima volta che lo si apriva.
* Corretto un warning PHP in SCC_Editable_Image::render() su ogni immagine
  ancora senza un override salvato.

= 2.7.0 =
* L'editor inline ora gestisce anche le immagini, non solo il testo: scc_image()
  o lo shortcode [scc_image] rendono un'immagine sostituibile dalla Media
  Library, con allineamento sinistra/centro/destra. Stessa infrastruttura gia'
  esistente (stesso interruttore ON/OFF, stessa capability, stesso blocco a
  licenza non attiva di scc_text()).
* I box di testo editabili (scc_text()) possono ora avere larghezza e altezza
  salvate, con whitelist server-side delle unita' CSS ammesse (px, %, em, rem,
  vw, vh, auto): niente altro puo' mai finire nello style inline.
* Due nuove rotte REST (scc/v1/image, scc/v1/text-style), protette dalle
  stesse regole delle altre: lettura sempre aperta, scrittura solo con
  licenza attiva e permesso edit_theme_options.

= 2.6.1 =
* A licenza non attiva, il modulo Form Configuration non mostra piu' le
  opzioni Transport e Redirect (restano visibili Webhook e messaggio
  inline). Enforcement anche lato server: un tentativo di salvare quei
  campi via POST a mano viene ignorato, restano i valori gia' salvati.
  Il modulo nativo continua a funzionare esattamente come prima.

= 2.6.0 =
* Rivisto cosa si ferma a licenza scaduta. I moduli di contatto NON si
  bloccano piu': continuano ad accettare invii anche a chiave scaduta o
  bloccata, cosi' un ritardo di fatturazione interno non diventa un
  disservizio per chi visita il sito del cliente.
* Testata e pie' di pagina (SCC_Site_Content) ora si fermano a licenza non
  attiva: tornano al markup predefinito del tema e non si possono piu'
  modificare, con lo stesso principio gia' in vigore per l'editor dei
  testi dalla 2.1.0.
* Il battito orario verso l'hub non parte piu' a licenza non attiva.
  Errori fatali, registro moduli e conferma di un aggiornamento continuano
  invece a raggiungere l'hub: sono le informazioni che servono a evitare
  un disservizio, non un privilegio legato alla licenza.

= 2.5.0 =
* Login automatico dall'hub: ora si puo' scegliere con quale amministratore
  entrare (invece del solo "primo amministratore trovato"). Il battito manda
  all'hub l'elenco degli amministratori (id, login, nome , mai la password
  o il suo hash).
* Il codice del login e' avvolto in try/catch dalla testa ai piedi: non puo'
  piu' portare il sito in errore critico anche in caso di problemi.

= 2.0.0 =
* Removed the Theme Settings module (colors/fonts via wp_theme_json_data_theme).
  Superseded by wp-design-tokens / Novamira DESIGN.md as the single source of
  design direction across all site strategies.
* Form Configuration and Entries screens unchanged in contract.
