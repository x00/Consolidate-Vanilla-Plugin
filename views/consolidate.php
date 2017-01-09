<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo $this->Data['Title']; ?></h1>
<?php
    echo $this->Form->open();
    echo $this->Form->errors();
?>
<style type="text/css">
input.SmallInput{
    width:100px;
}
input.ConsolidateInput{
    margin:0 5px;
}
.ConsolidateLabel{
    display:inline;
    float:none;
}

.ChunkGroupIndex{
    margin-top:30px;
}
</style>
<div class="Configuration">
   <div class="ConfigurationForm">
    <ul>
        <li>
            <?php
                echo $this->Form->label('Chunks'); 
                echo '<p> '.t('An ordered list of <a href="http://www.php.net/manual/en/pcre.pattern.php">PCRE</a> patterns used to divide resources in to consolidation chunks (applied first to last).').'</p>';
                echo '<p class="Warning"> '.t('Best left for those confident in what they are doing, some files need to be declared in a specific order to work properly. The defaults are recommended.').'</p>';
            ?>
        </li>
        <li>
            <?php
            $chunks = $this->data('Chunks');
            foreach($chunks as $chunkGroupIndex => $chunkGroup){
                echo '<div class="ChunkGroupIndex">';
                $chunkGroup['']='';
                foreach($chunkGroup as $chunk){
                    echo '<div class="Chunk">';
                    echo '<span class="ConsolidateLabel">'.t('File extension: <code>'.str_pad($chunkGroupIndex ? $chunkGroupIndex: '.js', 5, ' ').'</code> ').'</span>';
                    $this->Form->addHidden('ChunksExt[]',$chunkGroupIndex);
                    echo $this->Form->hidden('ChunksExt[]', array('value'=>$chunkGroupIndex, 'class'=>'SmallInput ConsolidateInput'));
                    echo '<span class="ConsolidateLabel">'.T('Match pattern:^').'</span>';
                    echo $this->Form->textBox('Chunks[]',array('value'=>$chunk, 'class'=>'InputBox ConsolidateInput'));
                    echo anchor('Remove','#',array('class'=>'SmallButton ConsolidateRemove'));
                    echo anchor(t('&uarr;'),'#',array('class'=>'UpConsolidate Button SmallButton'));
                    echo anchor(t('&darr;'),'#',array('class'=>'DownConsolidate Button SmallButton'));

                    echo '</div>';
                }
                echo '</div>';
            }
            ?>
        </li>
        <li>
            <?php 
            echo $this->Form->label('Defer JavasScript to Body foot'); 
            echo '<p class="Alert"> '.t('Warning! The following option to defer the JavasScript can easily break functionality. Use at your own risk. It will attempt to take declared JavaScript files and any inline JavaScript in the head, it can detect, and move it to the bottom. However there is no guarantee this will work as JavaScript can be added anywhere and in a magnitude of different ways, and these may depend on things that are ment to come before.').'</p>';
            ?>
        </li>
        <li>
            <?php
            echo '<span class="ConsolidateLabel">'.t('Defer JavaScript ').'</span>';
            echo $this->Form->dropDown('DeferJs', array(0=>'No', 1=>'Yes'), array('class'=>'SmallButton', 'value'=> c('Plugins.Consolidate.DeferJs'))); 
            ?>
        </li>
        <li>
            <?php echo $this->Form->button('Save Chunks', array('class'=>'SmallButton')); ?>
        </li>
    </ul>
    </div>
</div>
<?php
    echo $this->Form->close();
    echo $this->Form->open();
    echo $this->Form->errors();
?>
<div class="Configuration">
   <div class="ConfigurationForm">
    <ul>
        <li>
            <?php
                $this->Form->addHidden('ClearCache',1);
                echo $this->Form->hidden('ClearCache', array('value'=>1));
                echo $this->Form->label('Clear Cache');
                echo '<span class="ConsolidateLabel">'.t('This will wipe the cache, note users will have to explore the full site to get a complete cache. ').'</span>';
             ?>
        </li>
        </li>
            <?php echo $this->Form->button('Clear Cache',array('class'=>'SmallButton')); ?>
        </li>
    </ul>
    </div>
</div>
<?php
    echo $this->Form->close();
