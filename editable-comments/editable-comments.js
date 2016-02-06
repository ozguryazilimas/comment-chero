jQuery(document).ready(function($){   
	dialog_init('a.dialog');//pass where to apply thickbox
	$("body").append('<div id="dialog"><div id="dialog_content"></div></div>');
	$('#dialog').dialog({
		autoOpen: false,
		modal:true,
		width: 600
	});	
});

function dialog_init(elem){
	jQuery(elem).click(function(){
	var title = this.title;
	var url = this.href;
	dialog_show(title,url);
	this.blur();
	return false;
	});
}

function dialog_show(title,url){
	var $ = jQuery;
	if($('#dialog #dialog_content').find('form').length >0){
		$('#dialog #dialog_content form').remove();
	}
	$('#dialog #dialog_content').html('<p id="dialog_loader"><img src="'+ imgLoader +'" alt="loading..." /></p>');
	$('#dialog #dialog_content #dialog_loader').show();
	$('#dialog #dialog_content').load(
		url + ' #dialog_content', function(responseText, textStatus, XMLHttpRequest){
			$('#dialog #dialog_content h1').hide();
			$('#dialog').dialog('option', 'title', $('#dialog #dialog_content h1').text());
			$('#dialog').dialog('option', 'draggable', true);
			$('#dialog #dialog_content input.button').hover(
					function(){ 
						$(this).addClass("ui-state-hover"); 
					},
					function(){ 
						$(this).removeClass("ui-state-hover"); 
					}
				);			
		});
	$('#dialog').dialog('open');		
	
}

function dialog_validate(){
	var id = jQuery('#dialog_comment_ID').val();
	var content = jQuery('#dialog_comment').val();
	var url = window.location.href.split("#");
	url = url[0];
	validateEditableComments(url, id, content);
	return false;
}

function validateEditableComments(url,comment_ID,comment_content){
	var $ = jQuery;
	$('#dialog #dialog_content #dialog_loader').show();
	$("#comment-" + comment_ID).load(
		url + " #comment-" + comment_ID, 
		{ 'editable_comments_form': 1, 'comment_ID':comment_ID, 'comment':comment_content },
		function(responseText,textStatus, XMLHttpRequest){
			$('#dialog #dialog_content #dialog_loader').hide();
			dialog_init('a.dialog');
			jQuery('#dialog').dialog('close');
		}
	);
	
}