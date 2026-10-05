<?php
defined('ABSPATH') || exit;
final class SCC_MCP_Tools {
    public static function definitions(){
        $none=SCC_Dev_Schema::object(array());$limit=SCC_Dev_Schema::object(array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>30)));
        $read='sitecore_dev_context_read';$write='sitecore_dev_context_write';
        $tools=array(
            'bootstrap_project'=>array('Workspace: contesto, documenti e skill versionate pertinenti alla fase e al task. Inizia da qui una nuova sessione.',$read,SCC_Dev_Schema::object(array('phase'=>array('type'=>'string','enum'=>array('brief','seo','design','development','code','qa')),'task_id'=>array('type'=>'integer','minimum'=>1)),array('phase')),'bootstrap','GET'),
            'get_project_context'=>array('Contesto tecnico sintetico corrente.',$read,$none,'context','GET'),
            'get_development_decisions'=>array('Decisioni tecniche recenti.',$read,$limit,'decisions','GET'),
            'get_recent_changes'=>array('Modifiche significative recenti.',$read,$limit,'changes','GET'),
            'get_known_issues'=>array('Problemi aperti.',$read,$limit,'issues','GET'),
            'update_project_context'=>array('Aggiorna i campi forniti, richiede la revisione letta.',$write,SCC_Dev_Schema::object(array('revision'=>array('type'=>'integer','minimum'=>0),'context'=>SCC_Dev_Schema::context()),array('revision','context')),'context','PATCH'),
            'resolve_known_issue'=>array('Segna un problema del progetto come risolto.',$write,SCC_Dev_Schema::object(array('id'=>array('type'=>'integer','minimum'=>1)),array('id')),'issues','PATCH'),
            'list_skills'=>array('Metadata delle skill pubblicate in GestioneSiti.','sitecore_skill_read',SCC_Dev_Schema::object(array('search'=>SCC_Dev_Schema::text(100),'group'=>SCC_Dev_Schema::text(80),'category'=>SCC_Dev_Schema::text(80),'agent'=>SCC_Dev_Schema::text(80))),'skills','GET'),
            'get_skill'=>array('Legge una skill pubblicata su richiesta esplicita.','sitecore_skill_read',SCC_Dev_Schema::object(array('slug'=>array('type'=>'string','pattern'=>'^[a-z0-9-]{1,100}$')),array('slug')),'skills','GET'),
            'get_skill_version'=>array('Legge una versione pubblicata di una skill.','sitecore_skill_read',SCC_Dev_Schema::object(array('slug'=>array('type'=>'string','pattern'=>'^[a-z0-9-]{1,100}$'),'version'=>SCC_Dev_Schema::text(40)),array('slug','version')),'skills','GET'),
            'get_site_information'=>array('Versioni e architettura WordPress; nessun segreto.','sitecore_mcp_access',$none,'local','GET'),
            'get_opencode_setup'=>array('Manifest monouso per installare e autenticare automaticamente questo SiteCore nel progetto OpenCode corrente.','sitecore_mcp_manage',$none,'local','GET'),
            'list_content'=>array('Elenco sintetico pagine e articoli, senza dati utenti.','edit_posts',SCC_Dev_Schema::object(array('type'=>array('type'=>'string','enum'=>array('page','post')),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>30))),'local','GET'),
            'list_media'=>array('Elenco media e relativi identificatori.','upload_files',$limit,'local','GET'),
            'get_mcp_health'=>array('Verifica collegamento hub e Skill Registry.','sitecore_mcp_access',$none,'local','GET')
        );
        foreach(array('add_development_decision'=>'decisions','add_development_change'=>'changes','add_known_issue'=>'issues') as $tool=>$kind){$s=SCC_Dev_Schema::record($kind);$s['properties']['request_id']=array('type'=>'string','pattern'=>'^[a-zA-Z0-9_-]{16,64}$');$s['required'][]='request_id';$tools[$tool]=array('Registra una voce significativa. Riusa request_id solo per ritentare la stessa operazione.',$write,$s,$kind,'POST');}
        return array_merge($tools,SCC_MCP_Integrations::definitions());
    }
    public static function listing(){
        $out=array();foreach(self::definitions() as $name=>$d){if(!SCC_Developer_Permissions::allowed($d[1]))continue;$schema=$d[2];if(!$schema['properties'])$schema['properties']=new stdClass();$out[]=array('name'=>$name,'description'=>$d[0],'inputSchema'=>$schema,'annotations'=>array('readOnlyHint'=>$d[4]==='GET','destructiveHint'=>$d[4]!=='GET','idempotentHint'=>true,'openWorldHint'=>!in_array($d[3],array('local','integration'),true)),'securitySchemes'=>array(array('type'=>'oauth2','scopes'=>array('sitecore:mcp'))));}return $out;
    }
    public static function execute($name,$arguments){
        $defs=self::definitions();if(!isset($defs[$name]))return new WP_Error('unknown_tool','Strumento non disponibile.');$d=$defs[$name];
        if(!SCC_Developer_Permissions::allowed($d[1]))return new WP_Error('forbidden','Permesso MCP o WordPress richiesto.');
        $valid=SCC_Dev_Schema::validate($arguments,$d[2]);if(is_wp_error($valid))return new WP_Error('invalid_input','Parametri non validi per questo strumento.');
        if($d[3]==='integration')return SCC_MCP_Integrations::execute($name,$arguments);
        if($d[3]!=='local'){$key=$arguments['request_id']??'';unset($arguments['request_id']);return SCC_Dev_Client::request($d[3],$d[4],$arguments,$key);}
        if($name==='get_mcp_health')return SCC_Dev_Client::health();
        if($name==='get_opencode_setup')return SCC_MCP_Setup::opencode_setup();
        if($name==='get_site_information'){$theme=wp_get_theme();return array('site'=>home_url(),'wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'sitecore'=>SCC_VERSION,'theme'=>array('name'=>$theme->get('Name'),'version'=>$theme->get('Version')),'environment'=>wp_get_environment_type());}
        $limit=min(30,max(1,(int)($arguments['limit']??10)));
        if($name==='list_content'){$rows=get_posts(array('post_type'=>$arguments['type']??'page','post_status'=>'publish','numberposts'=>$limit));return array_map(function($p){return array('id'=>$p->ID,'title'=>$p->post_title,'slug'=>$p->post_name,'modified'=>$p->post_modified_gmt);},$rows);}
        if($name==='list_media'){$rows=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>$limit));return array_map(function($p){return array('id'=>$p->ID,'title'=>$p->post_title,'mime'=>$p->post_mime_type,'url'=>wp_get_attachment_url($p->ID));},$rows);}
        return new WP_Error('unknown_tool','Strumento non disponibile.');
    }
}
