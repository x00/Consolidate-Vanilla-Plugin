<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo $this->Data['Title']; ?></h1>
<?php
    echo $this->Form->Open();
	echo $this->Form->Errors();
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
				echo $this->Form->Label('Chunks'); 
				echo '<p> '.T('An ordered list of <a href="http://www.php.net/manual/en/pcre.pattern.php">PCRE</a> patterns used to divide resources in to consolidation chunks (applied first to last).').'</p>';
				echo '<p class="Warning"> '.T('Best left for those confident in what they are doing, some files need to be declared in a specific order to work properly. The defaults are recommended.').'</p>';
			?>
		</li>
		<li>
			<?php
			$Chunks = $this->Data('Chunks');
			foreach($Chunks As $ChunkGroupIndex => $ChunkGroup){
				echo '<div class="ChunkGroupIndex">';
				$ChunkGroup['']='';
				foreach($ChunkGroup As $Chunk){
					echo '<div class="Chunk">';
					echo '<span class="ConsolidateLabel">'.T('File extension: <code>'.str_pad($ChunkGroupIndex ? $ChunkGroupIndex: '.js',5,' ').'</code> ').'</span>';
					$this->Form->AddHidden('ChunksExt[]',$ChunkGroupIndex);
					echo $this->Form->Hidden('ChunksExt[]', array('value'=>$ChunkGroupIndex, 'class'=>'SmallInput ConsolidateInput'));
					echo '<span class="ConsolidateLabel">'.T('Match pattern:^').'</span>';
					echo $this->Form->TextBox('Chunks[]',array('value'=>$Chunk, 'class'=>'InputBox ConsolidateInput'));
					echo Anchor('Remove','#',array('class'=>'SmallButton ConsolidateRemove'));
					echo Anchor(T('&uarr;'),'#',array('class'=>'UpConsolidate Button SmallButton'));
					echo Anchor(T('&darr;'),'#',array('class'=>'DownConsolidate Button SmallButton'));

					echo '</div>';
				}
				echo '</div>';
			}
			?>
		</li>
		<li>
			<?php 
			echo $this->Form->Label('Defer JavasScript to Body foot'); 
			echo '<p class="Alert"> '.T('Warning! The following option to defer the JavasScript can easily break functionality. Use at your own risk. It will attempt to take declared JavaScript files and any inline JavaScript in the head, it can detect, and move it to the bottom. However there is no guarantee this will work as JavaScript can be added anywhere and in a magnitude of different ways, and these may depend on things that are ment to come before.').'</p>';
			?>
		</li>
		<li>
			<?php
			echo '<span class="ConsolidateLabel">'.T('Defer JavaScript ').'</span>';
			echo $this->Form->DropDown('DeferJs',array(0=>'No',1=>'Yes'), array('class'=>'SmallButton', 'value'=>C('Plugins.Consolidate.DeferJs'))); 
			?>
		</li>
		<li>
			<?php echo $this->Form->Button('Save Chunks',array('class'=>'SmallButton')); ?>
		</li>
	</ul>
	</div>
</div>
<?php
    echo $this->Form->Close();
    echo $this->Form->Open();
	echo $this->Form->Errors();
?>
<div class="Configuration">
   <div class="ConfigurationForm">
    <ul>
		<li>
			<?php
				$this->Form->AddHidden('ClearCache',1);
				echo $this->Form->Hidden('ClearCache', array('value'=>1));
				echo $this->Form->Label('Clear Cache');
				echo '<span class="ConsolidateLabel">'.T('This will wipe the cache, note users will have to explore the full site to get a complete cache. ').'</span>';
			 ?>
		</li>
		</li>
			<?php echo $this->Form->Button('Clear Cache',array('class'=>'SmallButton')); ?>
		</li>
	</ul>
	</div>
</div>
<?php
    echo $this->Form->Close();
