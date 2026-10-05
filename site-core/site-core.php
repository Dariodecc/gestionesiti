<?php
/**
 * Plugin Name: Site Core
 * Description: Native form engine (registry, handler, entries + logs, admin config) for hand-built WordPress sites. No third-party form plugin dependency.
 * Version: 2.18.0
 * Author: Dario D'Ecclesiis
 * Text Domain: site-core
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Update URI: https://gestionesiti.growbe.cloud/site-core
 *
 * v2.18.0: Posta dei form (SCC_Mailer). SMTP di sito configurabile da Form Configuration (password cifrata, mai mostrata, con
 * email di prova); per ogni form: notifica con destinatari, mittente (email e nome), modalita' di risposta (la casella accetta
 * risposte / Reply-To al visitatore / no-reply), oggetto e messaggio con segnaposto {campo}, {all_fields}, {form_label},
 * {site_name}, {site_url}, {entry_id}, {date}, e conferma opzionale al visitatore. L'esito entra nel registro delle fasi
 * (mail, mail_confirm) e un errore di invio non blocca il form. Nessuna migrazione: le chiavi nuove hanno valori predefiniti.
 * MCP: sitecore_get_form/sitecore_update_form leggono e scrivono i campi di posta, nuovo sitecore_get_smtp (senza password).
 *
 * v2.17.0: Due attivatori MCP indipendenti nell'HUB. L'accesso CLIENTE (mcp-access/check, invariato) governa gli account
 * WordPress del cliente; il nuovo accesso SVILUPPO (developer-access/check) governa un account dedicato, "growbe-developer"
 * (SCC_Developer_Principal), usato da OpenCode tramite il bridge. Spegnere l'accesso del cliente non taglia piu lo sviluppo e un
 * sito nuovo non resta bloccato. Collegamento automatico: il comando "opencode_link" nella risposta al battito prepara l'account,
 * collega la memoria di progetto e manda all'HUB il permesso monouso per il bridge, senza nessuna azione sul sito cliente.
 *
 * v2.16.0: Il modello contenuti (SCC_MCP_Content_Model) accetta ora un
 * "format" opzionale sui campi di tipo string (textarea, wysiwyg, email,
 * url, tel, date, datetime, time, color, select con scelte), oltre al
 * solo tipo grezzo string/integer/number/boolean di prima. Il battito
 * verso l'hub (SCC_Hub::tipi_contenuto_per_battito) dichiara questo
 * formato invece di appiattire tutto a "string": il form "Nuovo
 * contenuto" nella bacheca Growbe ora mostra il controllo giusto per
 * ogni campo invece di un solo campo di testo generico. Nessuna
 * migrazione: i campi esistenti restano string finche' non viene
 * assegnato loro un formato.
 *
 * v2.0.0: Theme Settings module removed. Design/theme.json direction is now
 * owned entirely by wp-design-tokens / Novamira DESIGN.md on sites that use it.
 * Site Core is scoped to what only it should own: native forms end-to-end.
 *
 * v2.5.0: Login automatico dall'hub esteso per scegliere l'amministratore
 * (invece del solo "primo admin"). Il battito manda anche l'elenco degli
 * amministratori (id, login, nome — mai password). Tutto il codice del
 * login e' avvolto in try/catch per non poter mai mandare in errore
 * critico il resto del sito.
 *
 * v2.6.0: Cosa succede davvero a licenza scaduta, rivisto. I moduli di
 * contatto NON si bloccano piu': un ritardo di fatturazione interno non
 * deve diventare un disservizio per chi visita il sito del cliente. Si
 * fermano invece l'editor dei testi (gia' cosi' dalla 2.1.0) e, da questa
 * versione, anche testata e pie' di pagina (SCC_Site_Content): a licenza
 * non attiva tornano al markup predefinito del tema e non si possono piu'
 * modificare. Il battito orario verso l'hub non parte piu' a licenza
 * scaduta, ma errori fatali, registro moduli e conferma di un aggiornamento
 * continuano a raggiungere l'hub: sono le informazioni che servono a
 * evitare un disservizio, non un privilegio legato alla licenza.
 *
 * v2.6.1: A licenza non attiva il modulo Form Configuration non mostra
 * piu' le opzioni Transport e Redirect (restano Webhook e messaggio
 * inline). Non e' solo estetico: anche un tentativo di salvare quei
 * campi via POST a mano viene ignorato lato server, che tiene i valori
 * gia' salvati in precedenza. Il modulo nativo continua a funzionare
 * esattamente come prima, a licenza scaduta.
 *
 * v2.7.0: L'editor inline sa gestire anche le immagini, non solo il
 * testo. Nuova classe SCC_Editable_Image (stesso interruttore ON/OFF,
 * stessa capability edit_theme_options, stesso blocco a licenza non
 * attiva di SCC_Editable_Text): scc_image( $id, $src_default, $alt_default )
 * o lo shortcode [scc_image] rendono un'immagine sostituibile dalla
 * Media Library, con allineamento sinistra/centro/destra. In piu',
 * SCC_Editable_Text ora salva anche larghezza e altezza del box di
 * testo (whitelist di unita' CSS: px/%/em/rem/vw/vh o auto, niente
 * altro passa mai allo style inline). Due nuove rotte REST protette
 * dalle stesse regole delle altre (capability + licenza attiva per
 * scrivere, lettura sempre aperta): scc/v1/image e scc/v1/text-style.
 *
 * v2.8.2: La Libreria media diventa un vero file manager: sidebar compatta
 * con ricerca e conteggi, cartelle e sottocartelle gestibili senza uscire dalla
 * Griglia, trascinamento di uno o piu' media, filtro Non assegnati e feedback
 * chiari. Tutte le azioni sono protette da nonce e permessi WordPress.
 *
 * v2.8.1: Cambiata l'interfaccia delle cartelle media introdotta in
 * 2.8.0, dopo prova dal vivo: via la tendina di filtro nella barra degli
 * strumenti della Griglia, al suo posto una sidebar ad albero sempre
 * visibile a sinistra (stile file-manager), solo nella pagina Libreria a
 * schermo intero (non nella modale "Aggiungi media", dove lo spazio non
 * basta). SCC_Media_Folders::carica_script_griglia() ora passa alla
 * pagina l'elenco piatto con il parent id di ognuna invece del nome gia'
 * indentato: l'albero lo costruisce il JS. Il filtro server (query
 * AJAX Griglia, tendina e colonna in vista Elenco, campo Cartella nel
 * pannello dettagli) resta lo stesso della 2.8.0, non toccato.
 *
 * v2.8.0: Due funzioni nuove, indipendenti tra loro.
 *
 * (1) Cartelle per la Libreria media (SCC_Media_Folders): tassonomia
 * gerarchica sugli allegati, tendina di filtro sia nella vista Elenco
 * sia nella Griglia (JS che estende AttachmentFilters/AttachmentsBrowser,
 * lo stesso meccanismo che usa WordPress per i suoi filtri "Tutti i tipi
 * di media"), e campo "Cartella" nel pannello dettagli tramite
 * attachment_fields_to_edit/save (compare sia nella Griglia sia nello
 * schermo classico Modifica media). Le cartelle si creano/rinominano/
 * cancellano dallo schermo nativo dei termini di WordPress, linkato dal
 * menu di Site Core , nessuna schermata reinventata apposta.
 *
 * (2) Ruolo "developer" (SCC_Install): creato all'attivazione con le
 * stesse capacita' di un amministratore piu' scc_developer, e assegnato
 * (in aggiunta, non al posto di quelli che gia' ha) a chi installa il
 * plugin. Su un sito dove il plugin e' gia' attivo il ruolo arriva con
 * il primo aggiornamento (SCC_Upgrade, passaggio 2.8.0), senza assegnarlo
 * a nessuno in automatico. Prepara solo il terreno: la connessione MCP
 * che distinguera' "developer" da un semplice amministratore arriva in
 * un secondo momento.
 * * v2.7.6: Due fix scoperti durante il retrofit sito-wide da mama_img a
 * scc_image su mamaitalianessence.com. (1) attachment_url_to_postid() non
 * risolve gli URL delle dimensioni ridimensionate (es. "...-1024x1024.webp"),
 * quindi molte immagini cadevano nel fallback <img> semplice senza
 * width/height/srcset: ora scc_image()/[scc_image] accettano anche un
 * attachment_id esplicito (nuovo parametro/attributo), che ha priorita' sulla
 * ricerca per URL. (2) class veniva omessa quando vuota, lasciando applicare
 * il default di WP "attachment-$size size-$size" invece di restare vuota
 * come fa mama_img: ora class e' sempre esplicita nell'attr passato a
 * wp_get_attachment_image(), anche quando vuota.
 *
 * v2.7.5: Parita' precisa con mama_img/wp_get_attachment_image in vista
 * del retrofit sito-wide delle immagini: la dimensione di default (quando
 * non specificata) e' ora 'large' come mama_img (non piu' 'full'), e
 * loading="lazy" viene impostato esplicitamente quando eager non e'
 * richiesto, invece di affidarsi all'euristica automatica di WP (che puo'
 * omettere l'attributo sulle prime immagini della pagina).
 *
 * v2.7.4: Aggiunti rotazione a 90 gradi e riempimento CSS (object-fit:
 * cover/contain/fill/none/scale-down) all'editor immagini, confinati SOLO
 * all'<img> stesso (mai al box/contenitore che lo ospita, che resta del
 * tema). Come per lo stile testo, il default resta quello del tema: viene
 * applicato uno style inline sull'immagine solo quando l'editor imposta
 * esplicitamente uno dei due, non di default. Nuovi campi fit/rotate in
 * scc_image_overrides e nella risposta REST di scc/v1/image.
 *
 * v2.7.3: Parita' prestazionale/markup completa per le immagini editabili
 * con quelle del tema. scc_image() ora, quando riesce a risolvere
 * l'allegato WordPress (sostituzione dalla Media Library, o URL di default
 * gia' presente in libreria via attachment_url_to_postid), genera il tag
 * con wp_get_attachment_image() invece di un <img> piatto: width/height
 * reali, srcset/sizes responsive, decoding=async, lazy-loading nativo. Nuovo
 * parametro/attributo eager (loading=eager + fetchpriority=high, per gli
 * hero above-the-fold) e size (dimensione registrata WP, default 'full').
 * Se l'URL non e' un allegato locale si ripiega su un <img> semplice, senza
 * regredire rispetto alla 2.7.2.
 *
 * v2.7.2: Fix regressione sulle immagini editabili (introdotte in 2.7.0).
 * scc_image()/[scc_image] non aggiungeva piu' lo style inline che rompeva
 * il markup, ma non passava affatto la class del tema: su hero e blocchi
 * con CSS del tema basato su class (es. object-fit:cover, object-position)
 * l'immagine spariva visivamente. Aggiunto un quarto parametro $default_class
 * a scc_image()/scc_get_image()/SCC_Editable_Image::render() e un attributo
 * class= al relativo shortcode, cosi' il markup risultante e' identico a
 * quello di wp_get_attachment_image() (nessuno style inline di
 * dimensionamento, il tema controlla tutto via CSS/class).
 *
 * v2.7.1: Due bug corretti, trovati testando la 2.7.0 su un sito vero.
 * (1) Il pannello larghezza/altezza dei box di testo: il campo non si
 * lasciava scrivere (un preventDefault sul mousedown del pannello
 * impediva al browser di dare il focus all'input) e compariva nel
 * punto sbagliato (l'altezza veniva misurata mentre il pannello era
 * ancora display:none, quindi sempre zero). (2) SCC_Editable_Image::
 * render() dava un warning PHP ("Undefined array key") su ogni
 * immagine senza ancora un override salvato.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCC_VERSION', '2.18.0' );
define( 'SCC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCC_URL', plugin_dir_url( __FILE__ ) );

require_once SCC_PATH . 'includes/class-scc-install.php';
require_once SCC_PATH . 'includes/class-scc-upgrade.php';
require_once SCC_PATH . 'includes/class-scc-updater.php';
require_once SCC_PATH . 'includes/class-scc-form-registry.php';
require_once SCC_PATH . 'includes/class-scc-mailer.php';
require_once SCC_PATH . 'includes/class-scc-form-handler.php';
require_once SCC_PATH . 'includes/class-scc-rest-controller.php';
require_once SCC_PATH . 'includes/class-scc-admin.php';
require_once SCC_PATH . 'includes/class-scc-site-content.php';
require_once SCC_PATH . 'includes/class-scc-license.php';
require_once SCC_PATH . 'includes/class-scc-entries-table.php';
require_once SCC_PATH . 'includes/class-scc-editable-text.php';
require_once SCC_PATH . 'includes/class-scc-editable-image.php';
require_once SCC_PATH . 'includes/class-scc-hub.php';
require_once SCC_PATH . 'includes/class-scc-media-folders.php';
require_once SCC_PATH . 'includes/developer-bootstrap.php';

register_activation_hook( __FILE__, array( 'SCC_Install', 'activate' ) );

/**
 * Extension point: themes/plugins hook this to call SCC_Form_Registry::register().
 * Fires early enough that forms are registered before the REST route / admin-post
 * handlers need to look them up, but after plugins are loaded.
 */
function scc_forms_loaded() {
	do_action( 'scc_register_forms' );
}
add_action( 'init', 'scc_forms_loaded', 5 );

/**
 * Front-end asset: forms.js + the nonce/REST-url it needs.
 * Enqueued on every front-end page load; it's a no-op on pages with no
 * [data-scc-form] element (the script checks before attaching listeners).
 */
function scc_enqueue_form_assets() {
	if ( is_admin() ) {
		return;
	}
	wp_enqueue_script( 'scc-forms', SCC_URL . 'assets/js/forms.js', array(), SCC_VERSION, true );
	wp_localize_script(
		'scc-forms',
		'sccForms',
		array(
			'restUrl' => esc_url_raw( rest_url( 'scc/v1/submit' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);
	wp_enqueue_style( 'scc-forms', SCC_URL . 'assets/css/forms.css', array(), SCC_VERSION );
}
add_action( 'wp_enqueue_scripts', 'scc_enqueue_form_assets' );

SCC_Mailer::init();
new SCC_REST_Controller();
new SCC_Admin();
new SCC_Site_Content();
new SCC_License();
new SCC_Editable_Text();
new SCC_Editable_Image();
new SCC_Hub();
new SCC_Media_Folders();
