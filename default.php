<?php
$PluginInfo['Consolidate'] = array(
   'Name' => 'Consolidate',
   'Description' => 'Combine resources (js, css) according to regex patterns, to have the ideal number of requests, and minimise bloat.',
   'Version' => '0.2.1b',
   'RequiredApplications' => array('Vanilla' => '2.3'),
   'Author' => "Paul Thomas",
   'AuthorEmail' => 'dt01pq_pt@yahoo.com',
   'AuthorUrl' => 'http://vanillaforums.org/profile/x00',
   'SettingsUrl' => '/dashboard/settings/consolidate',
   'MobileFriendy' => true
);



define('CONSOLIDATE_ROOT', dirname(__FILE__));

include_once(CONSOLIDATE_ROOT.DS.'CSS'.DS.'UriRewriter.php');
include_once(CONSOLIDATE_ROOT.DS.'CSS'.DS.'Compressor.php');

class Consolidate extends Gdn_Plugin {

    protected $checked = false;

    protected $webRoot = '';

    protected $chunks = array();
    protected $chunkedFiles = array();
    protected $deferJs = array();
    protected $externalJs = array();
    protected $inlineJs = array();
    protected $inlineJsStrings = array();
    protected $cdns = array();
   
    protected function chunkedFiles($put = array(), $save = true){
        $token = 'chunked_files';
        $cacheFile = PATH_CACHE."/Consolidate/$token";
        //check cache
        if (file_exists($cacheFile)) {
            $put = array_merge($put,Gdn_Format::unserialize(file_get_contents($cacheFile)));
            $put = array_unique($put);
            if (!$save) {
                return $put;
            }
        }
        //save
        if ($save && !empty($put)) {
            if (!file_exists(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0777, true);
            }
            file_put_contents($cacheFile, Gdn_Format::serialize($put));
        }
       
        return $put;
    }
   
    protected function getChunks(){
        $this->chunks = c('Plugins.Consolidate.Chunks',
        array(
                '.js' => array(
                    '/?js/library/jquery.js',
                    '/?js/global.js',
                    '/?js/.*',
                    '/?applications/.*',
                    '/?plugins/.*',
                    '/?themes/.*',
                    '.*'
                ),
                '.css' => array(
                    '/?applications/.*',
                    '/?resources/.*',
                    '/?plugins/.*',
                    '/?themes/.*',
                    '.*'
                )
            )
        );
      
        $this->chunkedFiles = $this->chunkedFiles(array());
    } 
   
    public function base_getAppSettingsMenuItems_handler($sender) {
        $menu = $sender->EventArguments['SideMenu'];
        $menu->addLink('Site Settings', t('Consolidate'), 'settings/consolidate', 'Garden.Settings.Manage');
    }
   
    public function settingsController_consolidate_create($sender, $args){
        $sender->permission('Garden.Settings.Manage');
        if ($sender->Form->isPostBack() != false) {
            $formValues = $sender->Form->formValues();
            if (val('ClearCache',$formValues)){
                $this->clearCache();
                redirect('settings/consolidate');
            }
            $chunkGroups = array();
            foreach ($formValues['Chunks'] As $chunkGroupIndex => $chunk) {
                
                if (!is_string($chunk) || preg_match('`^\s*$`',$chunk)) {
                    continue;
                }
                
                $ext = $formValues['ChunksExt'][$chunkGroupIndex];
                
                if (!is_string($ext)) {
                    continue;
                }
                
                if (!val($ext,$chunkGroups)) {
                    $chunkGroups[$ext] = array();
                }
                //in absense of escaping. 2.1
                $chunkGroups[$ext][] = trim(preg_replace('`([\'\$])`','\\\$1',$chunk));
            }
            
            foreach ($chunkGroups As $ext => $chunkGroup) {
                //ensure remainder (.*) is applied at end
                $i = array_search('.*',$chunkGroups[$ext]);
                if($i > -1){
                    unset($chunkGroups[$ext][$i]);
                }
                $chunkGroups[$ext][] = '.*';
            } 
            saveToConfig('Plugins.Consolidate.DeferJs',val('DeferJs', $formValues) ? true : false);
            if (!empty($chunkGroups)) {
                saveToConfig('Plugins.Consolidate.Chunks', $chunkGroups);
            }
            if (!empty($chunkGroups) || val('deferJs', $formValues)) {
                redirect('settings/consolidate');
            }
        }
        $sender->addSideMenu('settings/consolidate');
        $sender->setData('Title', t('Consolidate Settings'));
        $sender->addJsFile('consolidate.js', 'plugins/Consolidate');
        $this->getChunks();
        $sender->setData('Chunks', $this->chunks);
        $sender->Render($this->getView('consolidate.php'));
    }
   
    public function base_afterJscdns_handler($sender){
        $this->cdns = $sender->EventArguments['Cdns'];
    }
    
    public function headModule_beforeToString_handler($head) {
        if ($this->checked) {
            return;
        }
        
        $this->checked = true; 
       
        $this->webRoot = Gdn::request()->webRoot();
      
        $this->getChunks();
        $this->expireDeadCacheFiles();
      
        $tags = $head->tags();
        $cssToCache = array();
        $jsToCache = array(); 
        $externalJs = array();
        // Process all tags, finding JS & CSS files
        foreach ($tags as $index => $tag) {
            $isJs = val(HeadModule::TAG_KEY, $tag) == 'script';
            $isCss = val(HeadModule::TAG_KEY, $tag) == 'link' && val('rel', $tag) == 'stylesheet';
            if (!$isJs && !$isCss) {
                continue;
            }
            
            if ($isCss) {
                $href = val('href', $tag, '!');
            } else {
                $href = val('src', $tag, '!');
            
                if (isset($tag[HeadModule::CONTENT_KEY])) {
                    $href = '!';
                    unset($tags[$index]['src']);
                }
            
                if(c('Plugins.Consolidate.DeferJs') && $href=='!'){
                    $this->inlineJs[] = $tag;
                    unset($tags[$index]);
                    continue;
                }
                if(c('Plugins.Consolidate.DeferJs') && $href[0] != '/'){
                    $this->externalJs[] = $tag;
                    unset($tags[$index]);
                    continue;
                }
            }
        
            //ensure that cdn files are loaded first. 
            if (!empty($this->cdns) && in_array($href, $this->cdns)) {
                $tag['_sort']=intval($tag['_sort'])-300;
                $tags[$index] = $tag;
            }
            
        
             // Skip the rest if path doesn't start with a slash
            if ($href[0] != '/') {
                continue;
            }

            // Strip any querystring off the href.
            $hrefWithVersion = $href;
            $href = preg_replace('`\?.*`', '', $href);
         
             // Strip webRoot & extra slash from Href 
            if( $this->webRoot != '') {
                $href = preg_replace("`^/{$this->webRoot}/`U", '', $href);
            }

            // Skip the rest if the file doesn't exist
            $fixPath = ($href[0] != '/') ? '/' : ''; // Put that slash back to test for it in file structure
            $path = PATH_ROOT . $fixPath . $href;
            if (!file_exists($path)) {
                continue;
            }

            // Remove from the tag because consolidate is taking care of it.
            unset($tags[$index]);

            // Add the reference to the appropriate cache collection.
            if ($isCss) {
                $cssToCache[] = array('href'=>$href, 'fullhref'=>$hrefWithVersion);
            } elseif ($isJs) {
                $jsToCache[] = array('href'=>$href, 'fullhref'=>$hrefWithVersion);
            }
        }
      
        $head->tags($tags);
      
        $chunkedFilesTemp = $this->chunkedFiles;
        $chunkedCss = $this->chunk($cssToCache, '.css');
        foreach($chunkedCss As $cssChunkGroup => $cssChunk){
            $token = $this->consolidateFiles($cssChunk, '.css', $cssChunkGroup);
            $head->addCss("/cache/Consolidate/$token", 'screen', false);
        }
        $head = $this->seperateInlineScripts($head);
        Gdn::controller()->Assets['Head'] = $head;
        $chunkedJs = $this->chunk($jsToCache, '.js');
        foreach($chunkedJs As $jsChunkGroup => $jsChunk){
            $token = $this->consolidateFiles($jsChunk, '.js', $jsChunkGroup);
            if (!c('Plugins.Consolidate.DeferJs')) {
                $head->addScript("/cache/Consolidate/$token", 'text/javascript', (stripos($token,'_jquery_js')!==false) ? -100 : false);
            }else{
                $this->deferJs[]=$token;
            }
        }
      
        sort($this->chunkedFiles);
        sort($chunkedFilesTemp);
        if($this->chunkedFiles != $chunkedFilesTemp){
            $this->chunkedFiles($this->chunkedFiles, true);
        }
    }
   
    public function base_afterBody_handler($sender){
        if (!empty($this->deferJs)) {
            $head = new HeadModule();
            $sortLast = 0;

            foreach($this->externalJs As $externalJs){
                if(intval($externalJs['_sort'])>$sortLast){
                    $sortLast = intval($externalJs['_sort']);
                }
            }
            
            foreach($this->deferJs As $token){
                $sortLast++;
                $head->addScript("/cache/Consolidate/$token", 'text/javascript', (stripos($token, '_jquery_js')!==false) ? -100 : $sortLast);
            }
            $tags = $head->getTags();

            foreach($this->inlineJs as &$inlineJs) {
                if (stripos($inlineJs[HeadModule::CONTENT_KEY], 'gdn=window.gdn||{}')!==false) {
                    $inlineJs['_sort'] = -1000;
                }
            }
            $tags = array_merge($tags, $this->externalJs);
            $tags = array_merge($tags, $this->inlineJs);
            $head->tags($tags);
            $headParts = explode("\n", $head->ToString());
            $inScript = false;
            foreach($headParts As $headPart){
                if ($inScript || stripos($headPart, '<script')!==false) {
                    echo trim($headPart)."\n";
                    $inScript = stripos($headPart, '</script>')===false;
                }
            }
            foreach($this->inlineJsStrings As $inlineJsString){
                echo trim($inlineJsString)."\n";
            }
        }
    }
   
    protected function stripHTMLComments($matches){
        if(preg_match("`(<!--[\s]*\[if[^\]]*\]>[\s]*(-->)?)(.*?)((<!--)?[\s]*<!\[endif\][\s]*-->)`imsU", $matches[0])){
            return $matches[0];
        }
    }
   
    protected function escapeCommentedScript($matches){
        $quote = substr($matches[0], 0, 1);
        return preg_replace('`(scr)(ipt)`i', '\1'.$quote.'+'.$quote.'\2', $matches[0]);
    }
   
    protected function scriptSeperate($matches){
        $string = $matches[0];
        $this->inlineJsStrings[] = $string;
    }
   
    protected function stringsScriptSeperate($strings){
        $token = 'inline_'.md5(Gdn_Format::Serialize($strings));
        $cacheFile = PATH_CACHE."/Consolidate/$token";
        //check cache
        if (file_exists($cacheFile)) {
           $inline = Gdn_Format::unserialize(file_get_contents($cacheFile));
            if (val('Before', $inline)) {
                $strings = val('Before', $inline);
            }
            if (val('After',$inline)) {
                $this->inlineJsStrings = val('After', $inline);
            }
            if (!in_array($token, $this->chunkedFiles)) {
                $this->chunkedFiles[] = $token;
            }
           return $strings;
        }
        foreach($strings As &$string){ 
            //detect script
            if(stripos($string, '<script')!==false){
                //remove HTML comments
                $string = preg_replace_callback("`<!--(.*?)-->`imsU", array($this,'stripHTMLComments'), $string);    
                //escape quoted scripts
                $string = preg_replace_callback("`(?<!\\\\)'((.*?)<script[^>]*>(.*?)</script>(.*?))*?(?<!\\\\)'`imsU", array($this,'escapeCommentedScript'), $string);
                $string = preg_replace_callback("`(?<!\\\\)\"((.*?)<script[^>]*>(.*?)</script>(.*?))*?(?<!\\\\)\"`imsU", array($this,'escapeCommentedScript'), $string);
                //remove and save inline scripts (including conditional tags)
                $string = preg_replace_callback("`(<!--[\s]*\[if[^\]]*\]>[\s]*(-->)?)?<script[^>]*>(.*?)</script>((<!--)?[\s]*<!\[endif\][\s]*-->)?`imsU", array($this, 'scriptSeperate'), $string);
           }
        }
       
        $inline = array();
        if (!empty($strings)) {
            $inline['Before'] = $strings;
        }
        if (!empty($this->inlineJsStrings)) {
            $inline['After'] = $this->inlineJsStrings;
        }
        //cache
        if (!empty($inline)) {
            $inline = Gdn_Format::serialize($inline);
            if (!file_exists(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0777, true);
            }
            file_put_contents($cacheFile, $inline);
            if(!in_array($token, $this->chunkedFiles)) {
                $this->chunkedFiles[] = $token;
            }
        }
       
        return $strings;
    }
   
    protected function seperateInlineScripts($head){
        if (c('Plugins.Consolidate.DeferJs')) {
            $strings = $head->getStrings();
            $refObject = new ReflectionObject($head);
            $refProperty = $refObject->getProperty('_Strings');
            $refProperty->setAccessible(true);
            $refProperty->setValue($head, array());
            $strings = $this->stringsScriptSeperate($strings);
            foreach($strings As $string) {
                $head->addString($string);
            }
       }
       
       return $head;
    }
  
   
    protected function chunk($files, $suffix) {
        $chunks = val(strtolower($suffix), $this->chunks);
        if (empty($chunks)) {
            return array(Gdn_Format::url('.*')=>$files);
        }
        $chunkedFiles = array();
        foreach($chunks As $chunk){
            foreach($files As $fileIndex => $file){
                if(preg_match('`^'.$chunk.'`', $file['href'])){
                    $niceKey = trim(Gdn_Format::Url(preg_replace('`[^a-z0-9]+`i', '_', $chunk)), '_').'_';
                    if(!val($niceKey, $chunkedFiles)) {
                        $chunkedFiles[$niceKey] = array();
                    }
                    $chunkedFiles[$niceKey][] = $file;
                    unset($files[$fileIndex]);
                }
            }
        }
        return  $chunkedFiles;
    }
   
   
    protected function consolidateFiles($files, $suffix, $prefix = '') { 
        $token = $prefix.md5($this->PluginInfo['Version'].serialize($files)).$suffix;
        $cacheFile = PATH_CACHE."/Consolidate/$token";
        if (in_array($token, $this->chunkedFiles)) {
            return $token;
        }
        if (!file_exists($cacheFile)) {
            $consolidateFile = '';
            $pathRootParts = explode(DS, PATH_ROOT);
            $webRootParts = explode(DS, $this->webRoot);
            $base = join(DS, array_slice($pathRootParts, 0, -count($webRootParts)));
            foreach ($files As $file) {
                $consolidateFile .= "/*\n";
                $consolidateFile .= "* Consolidate '{$file['fullhref']}'\n";
                $consolidateFile .= "*/\n\n";
                $originalFile = PATH_ROOT.DS.$file['href'];
                $fileStr = file_get_contents($originalFile);
                if ($fileStr && strtolower($suffix)=='.css') {
                    $fileStr = Consolidate\Minify_CSS_UriRewriter::rewrite($fileStr, dirname($base.DS.$this->webRoot.DS.$file['href']), $base);             
                    $fileStr = Consolidate\Minify_CSS_Compressor::process($fileStr);
                }
                $consolidateFile .= trim($fileStr);
                if ($fileStr && strtolower($suffix)=='.js') {
                    if (substr($consolidateFile, -1)!=';') {
                        $consolidateFile .= ";";
                    }
                }
                $consolidateFile .= "\n\n";
            }
            if (!file_exists(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0777, true);
            }
            file_put_contents($cacheFile, $consolidateFile);
        }
        if (!in_array($token, $this->chunkedFiles)) {
            $this->chunkedFiles[] = $token;
        }
      
        return $token;
    }
   
    /**
    * Empty cache when disabling this plugin.
    */ 
    public function onDisable() { $this->ClearCache(); }
   
    /** 
    * Empty cache when enabling or disabling any other plugin, application, or theme.
    */
    public function settingsController_afterEnablePlugin_handler() { $this->ClearCache(); }
    public function settingsController_afterDisablePlugin_handler() { $this->ClearCache(); }
    public function settingsController_afterEnableApplication_handler() { $this->ClearCache(); }
    public function settingsController_afterDisableApplication_handler() { $this->ClearCache(); }
    public function settingsController_afterEnableTheme_handler() { $this->ClearCache(); }

    /**
    * Expire cache files.
    */
    private function expireDeadCacheFiles() {
        $expireLast = c('Plugins.Consolidate.ExpireLast');
        $expire = c('Plugins.Consolidate.ExpirePeriod', '1 Week');
        $expirePeriod = strtotime($expire);
        if (!$expireLast) {
            $expireLast = $expirePeriod;
        } 
        if ($expireLast<$expirePeriod) {
            return;
        }
        $chunkedFiles = $this->chunkedFiles;
        $files = glob(PATH_CACHE.'/Consolidate/*', GLOB_MARK);
        foreach ($files as $file) {
            $baseName = basename($file);
            if ($baseName=='chunked_files' || in_array($baseName, $chunkedFiles)) {
                continue;
            }
            if (filemtime($file)<$expirePeriod) {
                continue;
            }
            if (substr($file, -1) != '/') {
                unlink($file);
            }
        }
      
        saveToConfig('Plugins.Consolidate.ExpireLast', time());
    }
   
   
    /**
    * Empty cache.
    */
    private function clearCache() {
        $files = glob(PATH_CACHE.'/Consolidate/*', GLOB_MARK);
        foreach ($files as $file) {
            if (substr($file, -1) != '/') {
                unlink($file);
            }
        }
      
        $this->chunkedFiles(array(), true);
    }
   
}
