<?php
defined('ABSPATH') || exit;
/** Declarative, portable model. Never overwrite definitions owned by theme/plugins. */
final class SCC_MCP_Content_Model {
    const OPTION='scc_content_model';
    private static $owned=array('types'=>array(),'taxonomies'=>array(),'fields'=>array());
    private static $conflicts=array();
    public static function read(){return get_option(self::OPTION,array('revision'=>0,'types'=>array(),'taxonomies'=>array(),'fields'=>array()));}
    public static function handles($name){return in_array($name,array('wp_get_content_model','wp_define_content_type','wp_define_taxonomy','wp_define_field','wp_set_field_value'),true);}
    public static function definitions($add){
        $text=SCC_Dev_Schema::text(200);$key=array('type'=>'string','pattern'=>'^[a-z][a-z0-9_-]{0,19}$');$bool=array('type'=>'boolean');
        $base=array('key'=>$key,'revision'=>array('type'=>'integer','minimum'=>0),'operation'=>array('type'=>'string','enum'=>array('create','update')));
        $required=array('key','revision','operation','definition');
        $add('wp_get_content_model','Inventario e definizioni gestite da SiteCore, revisione e conflitti. Leggere prima di definire CPT, tassonomie o campi. Le definizioni esterne non vengono riscritte.','sitecore_mcp_access');
        $add('wp_define_content_type','Crea o modifica la definizione persistente di un CPT gestito da SiteCore. Non crea contenuti. Disponibile dalla richiesta successiva. Tipi di tema/plugin esistenti non possono essere sovrascritti.','manage_options',$base+array('definition'=>SCC_Dev_Schema::object(array('label'=>$text,'singular_label'=>$text,'public'=>$bool,'hierarchical'=>$bool,'has_archive'=>$bool,'supports'=>array('type'=>'array','maxItems'=>8,'uniqueItems'=>true,'items'=>array('type'=>'string','enum'=>array('title','editor','excerpt','thumbnail','author','revisions','custom-fields','page-attributes')))),array('label'))),$required,true);
        $add('wp_define_taxonomy','Crea o modifica una tassonomia gestita da SiteCore e i tipi associati. Non sovrascrive tassonomie esterne. Disponibile dalla richiesta successiva.','manage_options',$base+array('definition'=>SCC_Dev_Schema::object(array('label'=>$text,'public'=>$bool,'hierarchical'=>$bool,'object_types'=>array('type'=>'array','minItems'=>1,'maxItems'=>20,'uniqueItems'=>true,'items'=>$key)),array('label','object_types'))),$required,true);
        $add('wp_define_field','Definisce un campo scalare per un CPT gestito da SiteCore, con un formato di visualizzazione opzionale (solo sui campi di tipo string) che l\'hub usa per scegliere il controllo giusto nel form contenuti — es. textarea, select con scelte, data, email. Nessuna modifica a campi ACF/plugin/tema. Non cambia il tipo di un campo esistente.','manage_options',$base+array('post_type'=>$key,'definition'=>SCC_Dev_Schema::object(array('label'=>$text,'description'=>SCC_Dev_Schema::text(1000),'type'=>array('type'=>'string','enum'=>array('string','integer','number','boolean')),'format'=>array('type'=>'string','enum'=>array('text','textarea','wysiwyg','email','url','tel','date','datetime','time','color','select')),'choices'=>array('type'=>'object')),array('label','type'))),array_merge($required,array('post_type')),true);
        $add('wp_set_field_value','Imposta il valore di un campo SiteCore già definito, rispettando tipo e permessi sul contenuto.','sitecore_mcp_access',array('id'=>array('type'=>'integer','minimum'=>1),'key'=>$key,'value'=>array('type'=>array('string','number','boolean'),'maxLength'=>4000)),array('id','key','value'),true);
    }
    private static function owns($kind,$key){$object=$kind==='types'?get_post_type_object($key):get_taxonomy($key);return isset(self::$owned[$kind][$key])&&self::$owned[$kind][$key]===$object;}
    private static function owns_field($type,$key){$registered=get_registered_meta_keys('post',$type);return isset(self::$owned['fields'][$type][$key],$registered[$key])&&self::$owned['fields'][$type][$key]===$registered[$key];}
    private static function route_conflict($kind,$key){
        $base=($kind==='types'?'scc-':'scc-tax-').$key;
        foreach(get_post_types(array('show_in_rest'=>true),'objects') as $p)if(!($kind==='types'&&$p->name===$key)&&($p->rest_namespace??'wp/v2')==='wp/v2'&&($p->rest_base?:$p->name)===$base)return true;
        foreach(get_taxonomies(array('show_in_rest'=>true),'objects') as $t)if(!($kind==='taxonomies'&&$t->name===$key)&&($t->rest_namespace??'wp/v2')==='wp/v2'&&($t->rest_base?:$t->name)===$base)return true;
        return false;
    }
    public static function inventory(){
        $out=array();foreach(get_post_types(array('show_ui'=>true),'objects') as $p)if(current_user_can($p->cap->edit_posts))$out[]=array('name'=>$p->name,'label'=>$p->label,'rest_base'=>$p->rest_base?:$p->name,'taxonomies'=>get_object_taxonomies($p->name),'owner'=>self::owns('types',$p->name)?'sitecore':($p->_builtin?'wordpress':'external_theme_or_plugin'),'definition_editable'=>self::owns('types',$p->name)&&current_user_can('manage_options'),'mcp_content_adapter'=>SCC_MCP_Integrations::content_type_supported($p));return $out;
    }
    public static function register(){
        $model=self::read();self::$conflicts=array();
        foreach($model['types'] as $key=>$d){
            if(self::route_conflict('types',$key)){self::$conflicts[]='rest_route:'.$key;continue;}
            if(post_type_exists($key)&&!self::owns('types',$key)){self::$conflicts[]='post_type:'.$key;continue;}
            $tax=array();foreach($model['taxonomies'] as $name=>$t)if(in_array($key,$t['object_types'],true)&&(!taxonomy_exists($name)||self::owns('taxonomies',$name))&&!self::route_conflict('taxonomies',$name))$tax[]=$name;
            $args=array('labels'=>array('name'=>$d['label'],'singular_name'=>$d['singular_label']??$d['label']),'public'=>$d['public']??false,'hierarchical'=>$d['hierarchical']??false,'has_archive'=>$d['has_archive']??false,'show_ui'=>true,'show_in_rest'=>true,'rest_namespace'=>'wp/v2','rest_base'=>'scc-'.$key,'rewrite'=>array('slug'=>'scc-'.$key,'with_front'=>false),'capability_type'=>'post','map_meta_cap'=>true,'delete_with_user'=>false,'supports'=>$d['supports']??array('title','editor','revisions'),'taxonomies'=>$tax);
            $object=register_post_type($key,$args);if(!is_wp_error($object))self::$owned['types'][$key]=$object;
        }
        foreach($model['taxonomies'] as $key=>$d){
            if(self::route_conflict('taxonomies',$key)){self::$conflicts[]='rest_route:'.$key;continue;}
            if(taxonomy_exists($key)&&!self::owns('taxonomies',$key)){self::$conflicts[]='taxonomy:'.$key;continue;}
            $object=register_taxonomy($key,$d['object_types'],array('label'=>$d['label'],'public'=>$d['public']??false,'hierarchical'=>$d['hierarchical']??false,'show_ui'=>true,'show_in_rest'=>true,'rest_base'=>'scc-tax-'.$key,'rewrite'=>array('slug'=>'scc-tax-'.$key,'with_front'=>false)));if(!is_wp_error($object))self::$owned['taxonomies'][$key]=$object;
        }
        foreach($model['fields'] as $type=>$fields){
            if(!self::owns('types',$type))continue;
            foreach($fields as $key=>$d){$registered=get_registered_meta_keys('post',$type)+get_registered_meta_keys('post');if(isset($registered[$key])&&!self::owns_field($type,$key)){self::$conflicts[]='field:'.$type.':'.$key;continue;}
                add_post_type_support($type,'custom-fields');
                $ok=register_post_meta($type,$key,array('type'=>$d['type'],'single'=>true,'description'=>$d['description']??$d['label'],'show_in_rest'=>array('schema'=>array('type'=>$d['type'],'context'=>array('edit'))),'auth_callback'=>function($allowed,$meta_key,$post_id){return current_user_can('edit_post',$post_id);},'sanitize_callback'=>$d['type']==='string'?'sanitize_text_field':null));
                if($ok)self::$owned['fields'][$type][$key]=get_registered_meta_keys('post',$type)[$key];
            }
        }
        if((int)get_option('scc_content_model_rewrite_revision',0)!==(int)$model['revision']){flush_rewrite_rules(false);update_option('scc_content_model_rewrite_revision',(int)$model['revision'],false);}
    }
    public static function execute($name,$a){
        $model=self::read();
        if($name==='wp_get_content_model'){
            $tax=array();foreach(get_taxonomies(array('show_ui'=>true),'objects') as $t)if(current_user_can($t->cap->manage_terms))$tax[]=array('name'=>$t->name,'object_types'=>$t->object_type,'owner'=>self::owns('taxonomies',$t->name)?'sitecore':($t->_builtin?'wordpress':'external_theme_or_plugin'));
            return array('revision'=>$model['revision'],'content_types'=>self::inventory(),'taxonomies'=>$tax,'managed_definitions'=>current_user_can('manage_options')?$model:null,'conflicts'=>self::$conflicts,'ownership_note'=>'External means theme/plugin owned; exact provider is not inferred. Modify those definitions at their original source, not by duplicating them.');
        }
        if($name==='wp_set_field_value'){
            $post=get_post($a['id']);$d=$post?($model['fields'][$post->post_type][$a['key']]??null):null;
            if(!$post||!$d||!self::owns('types',$post->post_type)||!self::owns_field($post->post_type,$a['key'])||!current_user_can('edit_post',$post->ID)||!current_user_can('edit_post_meta',$post->ID,$a['key']))return new WP_Error('forbidden','Campo o contenuto non gestibile.');
            $valid=rest_validate_value_from_schema($a['value'],array('type'=>$d['type']));if(is_wp_error($valid))return $valid;
            $value=$d['type']==='string'?sanitize_text_field($a['value']):$a['value'];$saved=update_post_meta($post->ID,$a['key'],$value);
            if(!$saved&&(string)get_post_meta($post->ID,$a['key'],true)!==(string)$value)return new WP_Error('save_failed','Valore non salvato.');return array('id'=>$post->ID,'key'=>$a['key']);
        }
        if(!current_user_can('manage_options'))return new WP_Error('forbidden','Permesso di gestione richiesto.');
        if((int)$a['revision']!==(int)$model['revision'])return new WP_Error('stale_revision','Rileggi il modello: revisione cambiata.');
        $kind=$name==='wp_define_content_type'?'types':($name==='wp_define_taxonomy'?'taxonomies':'fields');$key=$a['key'];$update=$a['operation']==='update';$type=$a['post_type']??'';
        if(in_array($key,array('post','page','attachment','revision','nav_menu_item','category','post_tag','author','name','type','order','action'),true)||strpos($key,'wp_')===0)return new WP_Error('reserved_key','Identificatore riservato.');
        $existing=$kind==='fields'?($model['fields'][$type][$key]??null):($model[$kind][$key]??null);
        if($kind!=='fields'&&self::route_conflict($kind,$key))return new WP_Error('route_conflict','Endpoint REST già utilizzato da un altro componente.');
        if($update&&!$existing)return new WP_Error('not_owned','Definizione non gestita da SiteCore.');if(!$update&&$existing)return new WP_Error('already_exists','Definizione già presente.');
        if($kind==='types'){
            if(post_type_exists($key)&&!self::owns('types',$key))return new WP_Error('external_owner','CPT già gestito da tema/plugin.');
            if(!$update){global $wpdb;if($wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type=%s LIMIT 1",$key)))return new WP_Error('existing_data','Esistono contenuti con questo tipo: serve una migrazione esplicita.');}
        }elseif($kind==='taxonomies'){
            if(taxonomy_exists($key)&&!self::owns('taxonomies',$key))return new WP_Error('external_owner','Tassonomia già gestita da tema/plugin.');
            foreach($a['definition']['object_types'] as $object_type)if(!post_type_exists($object_type)&&!isset($model['types'][$object_type]))return new WP_Error('invalid_type','Tipo associato non esistente.');
        }else{
            if(!self::owns('types',$type))return new WP_Error('external_owner','I campi possono essere definiti solo sui CPT gestiti da SiteCore.');
            $registered=get_registered_meta_keys('post',$type)+get_registered_meta_keys('post');if(isset($registered[$key])&&!self::owns_field($type,$key))return new WP_Error('external_owner','Campo già registrato da un altro componente.');
            if($existing&&$existing['type']!==$a['definition']['type'])return new WP_Error('migration_required','Il cambio del tipo richiede una migrazione esplicita.');
            $formato=(string)($a['definition']['format']??'');
            if(''!==$formato&&'string'!==($a['definition']['type']??'string'))return new WP_Error('invalid_format','Il formato è disponibile solo per i campi di tipo string.');
            if('select'===$formato&&empty($a['definition']['choices']))return new WP_Error('choices_richieste','Il formato select richiede almeno una scelta.');
            if('select'!==$formato&&!empty($a['definition']['choices']))return new WP_Error('choices_non_previste','Le scelte sono previste solo per il formato select.');
        }
        $new=$model;$definition=array_replace($existing??array(),$a['definition']);foreach(array('label','singular_label','description') as $field)if(isset($definition[$field]))$definition[$field]=sanitize_text_field($definition[$field]);
        if($kind==='fields'&&isset($definition['choices'])&&is_array($definition['choices'])){$scelte=array();$i=0;foreach($definition['choices'] as $valore=>$etichetta){if(++$i>60)break;$scelte[sanitize_text_field((string)$valore)]=sanitize_text_field((string)$etichetta);}$definition['choices']=$scelte;}
        if($kind==='fields')$new[$kind][$type][$key]=$definition;else $new[$kind][$key]=$definition;
        if(count($new['types'])>40||count($new['taxonomies'])>40||strlen(wp_json_encode($new))>150000)return new WP_Error('model_limit','Limite modello raggiunto.');
        $new['revision']=(int)$model['revision']+1;
        // Compare-and-swap prevents lost updates from two concurrent developers.
        add_option(self::OPTION,$model,'','no');global $wpdb;
        $changed=$wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value=%s WHERE option_name=%s AND option_value=%s",maybe_serialize($new),self::OPTION,maybe_serialize($model)));
        if($changed!==1)return new WP_Error('stale_revision','Modello modificato contemporaneamente: rileggilo.');wp_cache_delete(self::OPTION,'options');
        return array('revision'=>$new['revision'],'key'=>$key,'effective_next_request'=>true);
    }
}
add_action('init',array('SCC_MCP_Content_Model','register'),99);
