{$uniq_id = uniqid()}
<form action="{devblocks_url}{/devblocks_url}" method="POST" id="frm{$uniq_id}" name="frm{$uniq_id}" onsubmit="return false;">
<input type="hidden" name="c" value="contacts">
<input type="hidden" name="a" value="showOrgMergeContinuePeek">
<input type="hidden" name="view_id" value="{$view_id}">
<input type="hidden" name="_csrf_token" value="{$session.csrf_token}">

<b>Select organizations to merge:</b><br>
<button type="button" class="chooser_orgs"><span class="glyphicons glyphicons-search"></span></button>
<ul class="chooser-container bubbles" style="display:block;">
{if !empty($orgs)}
{foreach from=$orgs item=merge_org key=merge_org_id}
<li>
	<input type="hidden" name="org_id[]" value="{$merge_org_id}">
	{$merge_org->name}
	<a href="javascript:;" onclick="$(this).parent().remove();"><span class="glyphicons glyphicons-circle-remove"></span></a>
</li>
{/foreach}
{/if}
</ul>
<br>

{if $active_worker->hasPriv('contexts.cerberusweb.contexts.org.merge')}
	<button type="button" class="submit"><span class="glyphicons glyphicons-circle-ok" style="color:rgb(0,180,0);"></span> {'common.continue'|devblocks_translate|capitalize}</button>
{/if}
</form>

<script type="text/javascript">
$(function() {
	var $popup = genericAjaxPopupFetch('peek');
	
	$popup.one('popup_open',function(event,ui) {
		$(this).dialog('option','title', "Merge Organizations");
		$frm = $('#frm{$uniq_id}');
		
		// Autocomplete
		$frm.find('button.chooser_orgs').each(function() {
			ajax.chooser(this,'cerberusweb.contexts.org','org_id', { autocomplete:true });
			$frm.find(':input:text:first').focus();
		});

		$('#frm{$uniq_id} BUTTON.submit').click(function() {
			// Replace the current form
			genericAjaxPost('frm{$uniq_id}','popuppeek','');
		});
	});
});
</script>