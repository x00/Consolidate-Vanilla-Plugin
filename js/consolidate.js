jQuery(document).ready(function($){
	$('a.ConsolidateRemove').click(function(e){
		e.preventDefault();
		$(this).parent('div.Chunk').remove();
	});
	
	$('div.ChunkGroupIndex div.Chunk').livequery(function(){
		$(this).keyup(function(){
			if($(this).is(':last-child')){
				var ext = $($(this).children('.ConsolidateInput:eq(0)')).val().replace(/\s/g,'');
				var pattern = $($(this).children('.ConsolidateInput:eq(1)')).val().replace(/\s/g,'');
				if(ext!='' && pattern!=''){
					var clone = $(this).clone();
					clone.children('.ConsolidateInput:eq(1)').val('');
					$(this).after(clone);
				}
			}
		});
		var that = this;
		$(that).children('.UpConsolidate').click(function(e){
			e.preventDefault();
			$(that).insertBefore($(that).prev());
		});
		$(that).children('.DownConsolidate').click(function(e){
			e.preventDefault();
			$(that).insertAfter($(that).next());
		});
	});
});
