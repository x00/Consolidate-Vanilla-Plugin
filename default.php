<?php
$PluginInfo['Consolidate'] = array(
   'Name' => 'Consolidate',
   'Description' => 'Combine resources (js, css) according to regex patterns, to have the ideal number of requests, and minimise bloat.',
   'Version' => '0.2.0b',
   'RequiredApplications' => array('Vanilla' => '2.2'),
   'Author' => "Paul Thomas",
   'AuthorEmail' => 'dt01pq_pt@yahoo.com',
   'AuthorUrl' => 'http://vanillaforums.org/profile/x00',
   'SettingsUrl' => '/dashboard/settings/consolidate',
   'MobileFriendy' => TRUE
);



define('CONSOLIDATE_ROOT', dirname(__FILE__));

if(!class_exists('Minify_CSS_UriRewriter')){
    include_once(CONSOLIDATE_ROOT.DS.'CSS'.DS.'UriRewriter.php');
}

if(!class_exists('Minify_CSS_Compressor')){
    include_once(CONSOLIDATE_ROOT.DS.'CSS'.DS.'Compressor.php');
}

if(C('Plugins.Consolidate.DeferJs') && !class_exists('HeadModule', FALSE)){
    include_once(CONSOLIDATE_ROOT.DS.'class.headmodule.php');
}

if(!function_exists('realpath2')){
    function realpath2($path) {
       $parts = explode('/', str_replace('\\', '/', $path));
       $result = array();

       foreach ($parts as $part) {
          if (!$part || $part == '.')
             continue;
          if ($part == '..')
             array_pop($result);
          else
             $result[] = $part;
       }
       $result = '/'.implode('/', $result);

       // Do a sanity check.
       if (realpath($result) != realpath($path))
          $result = realpath($path);

       return $result;
    }
}

class Consolidate extends Gdn_Plugin {
    
   protected $Checked = FALSE;
    
   protected $WebRoot = '';
   
   protected $Chunks = array();
   protected $ChunkedFiles = array();
   protected $DeferJs = array();
   protected $ExternalJs = array();
   protected $InlineJs = array();
   protected $InlineJsStrings = array();
   protected $Cdns = array();
   
   protected function ChunkedFiles($Put = array(),$Save = TRUE){
       $Token = 'chunked_files';
       $CacheFile = PATH_CACHE."/Consolidate/$Token";
       //check cache
       if(file_exists($CacheFile)){
           $Put = array_merge($Put,Gdn_Format::Unserialize(file_get_contents($CacheFile)));
           $Put = array_unique($Put);
           if(!$Save)
              return $Put;
       }
       //save
       if($Save && !empty($Put)){
           if (!file_exists(dirname($CacheFile)))
                mkdir(dirname($CacheFile), 0777, TRUE);
           file_put_contents($CacheFile, Gdn_Format::Serialize($Put));
       }
       
       return $Put;
   }
   
   protected function GetChunks(){
      $this->Chunks = C('Plugins.Consolidate.Chunks',
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
      
      $this->ChunkedFiles = $this->ChunkedFiles(array());
   } 
   
    public function Base_GetAppSettingsMenuItems_Handler($Sender) {
        $Menu = $Sender->EventArguments['SideMenu'];
        $Menu->AddLink('Site Settings', T('Consolidate'), 'settings/consolidate', 'Garden.Settings.Manage');
    }
   
   public function SettingsController_Consolidate_Create($Sender,$Args){
        $Sender->Permission('Garden.Settings.Manage');
        if($Sender->Form->IsPostBack() != False){
            $FormValues = $Sender->Form->FormValues();
            if(GetValue('ClearCache',$FormValues)){
                $this->_ClearCache();
                Redirect('settings/consolidate');
            }
            $ChunkGroups = array();
            foreach($FormValues['Chunks'] As $ChunkGroupIndex => $Chunk){
                
                if(!is_string($Chunk) || preg_match('`^\s*$`',$Chunk))
                    continue;
                
                $Ext = $FormValues['ChunksExt'][$ChunkGroupIndex];
                
                if(!is_string($Ext))
                    continue;
                
                if(!GetValue($Ext,$ChunkGroups))
                    $ChunkGroups[$Ext] = array();
                //in absense of escaping. 2.1
                $ChunkGroups[$Ext][] = trim(preg_replace('`([\'\$])`','\\\$1',$Chunk));
            }
            
            foreach($ChunkGroups As $Ext => $ChunkGroup){
                //ensure remainder (.*) is applied at end
                $I = array_search('.*',$ChunkGroups[$Ext]);
                if($I > -1){
                    unset($ChunkGroups[$Ext][$I]);
                }
                $ChunkGroups[$Ext][] = '.*';
            } 
            SaveToConfig('Plugins.Consolidate.DeferJs',GetValue('DeferJs',$FormValues) ? TRUE : FALSE);
            if(!empty($ChunkGroups)){
                SaveToConfig('Plugins.Consolidate.Chunks',$ChunkGroups);

            }
            if(!empty($ChunkGroups) || GetValue('DeferJs',$FormValues)){
                Redirect('settings/consolidate');
            }
        }
        $Sender->AddSideMenu('settings/consolidate');
        $Sender->SetData('Title', T('Consolidate Settings'));
        $Sender->AddJsFile('consolidate.js','plugins/Consolidate');
        $this->GetChunks();
        $Sender->SetData('Chunks',$this->Chunks);
        $Sender->Render($this->GetView('consolidate.php'));
   }
   
   public function Base_AfterJsCdns_Handler($Sender){
        $this->Cdns = $Sender->EventArguments['Cdns'];
   }
    
   public function HeadModule_BeforeToString_Handler($Head) {
      if($this->Checked)
        return;
        
      $this->Checked = TRUE; 
       
      $this->WebRoot = Gdn::Request()->WebRoot();
      
      $this->GetChunks();
      $this->_ExpireDeadCacheFiles();
      
      $Tags = $Head->Tags();
      $CssToCache = array();
      $JsToCache = array(); 
      $ExternalJs = array();
      // Process all tags, finding JS & CSS files
      foreach ($Tags as $Index => $Tag) {
         $IsJs = GetValue(HeadModule::TAG_KEY, $Tag) == 'script';
         $IsCss = GetValue(HeadModule::TAG_KEY, $Tag) == 'link' && GetValue('rel', $Tag) == 'stylesheet';
         if (!$IsJs && !$IsCss)
            continue;

        if($IsCss){
            $Href = GetValue('href', $Tag, '!');
        } else {
            $Href = GetValue('src', $Tag, '!');
            
            if(isset($Tag[HeadModule::CONTENT_KEY])) {
                $Href = '!';
                unset($Tags[$Index]['src']);
            }
            
            if(C('Plugins.Consolidate.DeferJs') && $Href=='!'){
                $this->InlineJs[] = $Tag;
                unset($Tags[$Index]);
                continue;
            }
            if(C('Plugins.Consolidate.DeferJs') && $Href[0] != '/'){
                $this->ExternalJs[] = $Tag;
                unset($Tags[$Index]);
                continue;
            }

        }
        
        //ensure that cdn files are loaded first. 
        if(!empty($this->Cdns) && in_array($Href,$this->Cdns)){
            $Tag['_sort']=intval($Tag['_sort'])-300;
            $Tags[$Index] = $Tag;
        }
        
        
         // Skip the rest if path doesn't start with a slash
         if ($Href[0] != '/')
            continue;

         // Strip any querystring off the href.
         $HrefWithVersion = $Href;
         $Href = preg_replace('`\?.*`', '', $Href);
         
         // Strip WebRoot & extra slash from Href 
         if($this->WebRoot != '')
            $Href = preg_replace("`^/{$this->WebRoot}/`U", '', $Href);
            
         
            
         // Skip the rest if the file doesn't exist
         $FixPath = ($Href[0] != '/') ? '/' : ''; // Put that slash back to test for it in file structure
         $Path = PATH_ROOT . $FixPath . $Href;
         if (!file_exists($Path))
            continue;

         // Remove from the tag because consolidate is taking care of it.
         unset($Tags[$Index]);

         // Add the reference to the appropriate cache collection.
         if ($IsCss) {
            $CssToCache[] = array('href'=>$Href, 'fullhref'=>$HrefWithVersion);
         } elseif ($IsJs) {
            $JsToCache[] = array('href'=>$Href, 'fullhref'=>$HrefWithVersion);
         }
         
      }
      
      $Head->Tags($Tags);
      
      $ChunkedFilesTemp = $this->ChunkedFiles;
      $ChunkedCss = $this->_Chunk($CssToCache, '.css');
      foreach($ChunkedCss As $CssChunkGroup => $CssChunk){
        if(count($CssChunk)==1) {
          $Head->AddCss('/'.$CssChunk[0]['href'], 'screen', true);
        } else {
          $Token = $this->_Consolidate($CssChunk, '.css', $CssChunkGroup);
          $Head->AddCss("/cache/Consolidate/$Token", 'screen', FALSE);
        }
      }
      $Head = $this->_SeperateInlineScripts($Head);
      Gdn::Controller()->Assets['Head'] = $Head;
      $ChunkedJs = $this->_Chunk($JsToCache, '.js');
      foreach($ChunkedJs As $JsChunkGroup => $JsChunk){
        $Token = $this->_Consolidate($JsChunk, '.js', $JsChunkGroup);
        if(!C('Plugins.Consolidate.DeferJs')){
            $Head->AddScript("/cache/Consolidate/$Token", 'text/javascript', (stripos($Token,'_jquery_js')!==FALSE) ? -100 : FALSE);
        }else{
            $this->DeferJs[]=$Token;
        }
      }
      
      sort($this->ChunkedFiles);
      sort($ChunkedFilesTemp);
      if($this->ChunkedFiles != $ChunkedFilesTemp){
          $this->ChunkedFiles($this->ChunkedFiles, TRUE);
      }
   }
   
   public function Base_AfterBody_Handler($Sender){
       if(!empty($this->DeferJs)){
           $Head = new HeadModule();
           $SortLast = 0;

           foreach($this->ExternalJs As $ExternalJs){
                if(intval($ExternalJs['_sort'])>$SortLast){
                    $SortLast = intval($ExternalJs['_sort']);
                }
            }
            
           foreach($this->DeferJs As $Token){
               $SortLast++;
               $Head->AddScript("/cache/Consolidate/$Token", 'text/javascript', (stripos($Token,'_jquery_js')!==FALSE) ? -100 : $SortLast);
           }
           $Tags = $Head->GetTags();

       
           $Tags = array_merge($Tags,$this->ExternalJs);
           $Tags = array_merge($Tags, $this->InlineJs);
           $Head->Tags($Tags);
           $HeadParts = split("\n",$Head->ToString());
           foreach($HeadParts As $HeadPart){
               if(stripos($HeadPart,'<script')!==FALSE)
                    echo trim($HeadPart)."\n";
           }
           
           foreach($this->InlineJsStrings As $InlineJsString){
               echo trim($InlineJsString)."\n";
           }
       }
   }
   
   protected function _StripHTMLComments($Matches){
       if(preg_match("`(<!--[\s]*\[if[^\]]*\]>[\s]*(-->)?)(.*?)((<!--)?[\s]*<!\[endif\][\s]*-->)`imsU",$Matches[0])){
           return $Matches[0];
       }
   }
   
   protected function _EscapeCommentedScript($Matches){
       $Quote = substr($Matches[0],0,1);
       return preg_replace('`(scr)(ipt)`i','\1'.$Quote.'+'.$Quote.'\2',$Matches[0]);
   }
   
   protected function _ScriptSeperate($Matches){
       $String = $Matches[0];
       $this->InlineJsStrings[] = $String;
   }
   
   protected function _StringsScriptSeperate($Strings){
       $Token = 'inline_'.md5(Gdn_Format::Serialize($Strings));
       $CacheFile = PATH_CACHE."/Consolidate/$Token";
       //check cache
       if(file_exists($CacheFile)){
           $Inline = Gdn_Format::Unserialize(file_get_contents($CacheFile));
           if(GetValue('Before',$Inline)){
               $Strings = GetValue('Before',$Inline);
           }
           if(GetValue('After',$Inline)){
               $this->InlineJsStrings = GetValue('After',$Inline);
           }
           if(!in_array($Token,$this->ChunkedFiles))
                $this->ChunkedFiles[] = $Token;
           return $Strings;
       }
       foreach($Strings As &$String){ 
           //detect script
           if(stripos($String, '<script')!==FALSE){
               //remove HTML comments
               $String = preg_replace_callback("`<!--(.*?)-->`imsU",array($this,'_StripHTMLComments'),$String);    
               //escape quoted scripts
               $String = preg_replace_callback("`(?<!\\\\)'((.*?)<script[^>]*>(.*?)</script>(.*?))*?(?<!\\\\)'`imsU",array($this,'_EscapeCommentedScript'),$String);
               $String = preg_replace_callback("`(?<!\\\\)\"((.*?)<script[^>]*>(.*?)</script>(.*?))*?(?<!\\\\)\"`imsU",array($this,'_EscapeCommentedScript'),$String);
               //remove and save inline scripts (including conditional tags)
               $String = preg_replace_callback("`(<!--[\s]*\[if[^\]]*\]>[\s]*(-->)?)?<script[^>]*>(.*?)</script>((<!--)?[\s]*<!\[endif\][\s]*-->)?`imsU",array($this,'_ScriptSeperate'),$String);
               
               
           }
           
       }
       
       $Inline = array();
       if(!empty($Strings)){
           $Inline['Before'] = $Strings;
       }
       if(!empty($this->InlineJsStrings)){
           $Inline['After'] = $this->InlineJsStrings;
       }
       //cache
       if(!empty($Inline)){
           $Inline = Gdn_Format::Serialize($Inline);
           if (!file_exists(dirname($CacheFile)))
                mkdir(dirname($CacheFile), 0777, TRUE);
           file_put_contents($CacheFile, $Inline);
           if(!in_array($Token,$this->ChunkedFiles))
                $this->ChunkedFiles[] = $Token;
       }
       
       return $Strings;
   }
   
   protected function _SeperateInlineScripts($Head){
       if(C('Plugins.Consolidate.DeferJs')){
           $Strings = $Head->GetStrings();
           $Head->ClearStrings();
           $Strings = $this->_StringsScriptSeperate($Strings);
           foreach($Strings As $String)
                $Head->AddString($String);

       }
       
       return $Head;
   }
  
   
   protected function _Chunk($Files, $Suffix) {
       $Chunks = GetValue(strtolower($Suffix),$this->Chunks);
       if(empty($Chunks))
            return array(Gdn_Format::Url('.*')=>$Files);
       $ChunkedFiles = array();
       foreach($Chunks As $Chunk){
           foreach($Files As $FileIndex => $File){
               if(preg_match('`^'.$Chunk.'`', $File['href'])){
                   $NiceKey = trim(Gdn_Format::Url(preg_replace('`[^a-z0-9]+`i','_',$Chunk)),'_').'_';
                   if(!GetValue($NiceKey,$ChunkedFile))
                        $ChunkedFile[$NiceKey] = array();
                   $ChunkedFiles[$NiceKey][] = $File;
                   unset($Files[$FileIndex]);
               }
           }
       }
       
       return  $ChunkedFiles;
   }
   
   
   protected function _Consolidate($Files, $Suffix, $Prefix = '') { 
      $Token = $Prefix.md5(serialize($Files)).$Suffix;
      $CacheFile = PATH_CACHE."/Consolidate/$Token";
      if(in_array($Token,$this->ChunkedFiles))
        return $Token;
      if (!file_exists($CacheFile)) {
          $ConsolidateFile = '';
          $PathRootParts = split(DS,PATH_ROOT);
          $WebRootParts = split(DS,$this->WebRoot);
          $Base = join(DS,array_slice($PathRootParts,0,-count($WebRootParts)));
          foreach($Files As $File){
              $ConsolidateFile .= "/*\n";
              $ConsolidateFile .= "* Consolidate '{$File['fullhref']}'\n";
              $ConsolidateFile .= "*/\n\n";
              $OriginalFile = PATH_ROOT.DS.$File['href'];
              $FileStr = file_get_contents($OriginalFile);
              if($FileStr && strtolower($Suffix)=='.css'){
                  $FileStr = Minify_CSS_UriRewriter::rewrite($FileStr,dirname($Base.DS.$this->WebRoot.DS.$File['href']),$Base);
                  $FileStr = Minify_CSS_Compressor::process($FileStr);
              }
              $ConsolidateFile .= trim($FileStr);
              if($FileStr && strtolower($Suffix)=='.js'){
                  if(substr($ConsolidateFile,-1)!=';')
                    $ConsolidateFile .= ";";
              }
              $ConsolidateFile .= "\n\n";
          }
         if (!file_exists(dirname($CacheFile)))
            mkdir(dirname($CacheFile), 0777, TRUE);
         file_put_contents($CacheFile, $ConsolidateFile);
      }
      if(!in_array($Token,$this->ChunkedFiles))
          $this->ChunkedFiles[] = $Token;
      
      return $Token;
   }
   
   /**
    * Empty cache when disabling this plugin.
    */ 
   public function OnDisable() { $this->_ClearCache(); }
   
   /** 
    * Empty cache when enabling or disabling any other plugin, application, or theme.
    */
   public function SettingsController_AfterEnablePlugin_Handler() { $this->_ClearCache(); }
   public function SettingsController_AfterDisablePlugin_Handler() { $this->_ClearCache(); }
   public function SettingsController_AfterEnableApplication_Handler() { $this->_ClearCache(); }
   public function SettingsController_AfterDisableApplication_Handler() { $this->_ClearCache(); }
   public function SettingsController_AfterEnableTheme_Handler() { $this->_ClearCache(); }
   
   /**
    * Expire cache files.
    */
   private function _ExpireDeadCacheFiles() {
      $ExpireLast = C('Plugins.Consolidate.ExpireLast');
      $Expire = C('Plugins.Consolidate.ExpirePeriod','1 Week');
      $ExpirePeriod = strtotime($Expire);
      if(!$ExpireLast){
          $ExpireLast = $ExpirePeriod;
      } 
      if($ExpireLast<$ExpirePeriod)
        return;
      $ChunkedFiles = $this->ChunkedFiles;
      $Files = glob(PATH_CACHE.'/Consolidate/*', GLOB_MARK);
      foreach ($Files as $File) {
         $BaseName = basename($File);
         if($BaseName=='chunked_files' || in_array($BaseName,$ChunkedFiles))
            continue;
         if(filemtime($File)<$ExpirePeriod)
            continue;
         if (substr($File, -1) != '/')
            unlink($File);
      }
      
      SaveToConfig('Plugins.Consolidate.ExpireLast', time());
   }
   
   
   /**
    * Empty cache.
    */
   private function _ClearCache() {
      $Files = glob(PATH_CACHE.'/Consolidate/*', GLOB_MARK);
      foreach ($Files as $File) {
         if (substr($File, -1) != '/')
            unlink($File);
      }
      
      $this->ChunkedFiles(array(), TRUE);
   }
   
    
}
