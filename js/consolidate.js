jQuery(document).ready(function($){
    $('body').on('click', 'a.ConsolidateRemove',function(e){
        e.preventDefault();
        $(this).parent('div.Chunk').remove();
    });
    $('body').on('keyup','div.ChunkGroupIndex div.Chunk',function(){
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
    
    $('body').on('click', 'div.ChunkGroupIndex div.Chunk .UpConsolidate',function(e){
        e.preventDefault();
        $(this).parents('div.Chunk').insertBefore($(this).parents('div.Chunk').prev());
    });
    
    $('body').on('click', 'div.ChunkGroupIndex div.Chunk .DownConsolidate',function(e){
        e.preventDefault();
        $(this).parents('div.Chunk').insertAfter($(this).parents('div.Chunk').next());
    });
});
