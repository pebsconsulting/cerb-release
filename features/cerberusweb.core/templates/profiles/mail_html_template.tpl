{$page_context = CerberusContexts::CONTEXT_MAIL_HTML_TEMPLATE}
{$page_context_id = $mail_html_template->id}
{$is_writeable = Context_MailHtmlTemplate::isWriteableByActor($mail_html_template, $active_worker)}

<h1>{$mail_html_template->name}</h1>

<div class="cerb-profile-toolbar">
	<form class="toolbar" action="{devblocks_url}{/devblocks_url}" onsubmit="return false;" style="margin-bottom:5px;">
		<input type="hidden" name="_csrf_token" value="{$session.csrf_token}">
		
		<!-- Edit -->
		{if $is_writeable && $active_worker->hasPriv("contexts.{$page_context}.update")}
		<button type="button" id="btnDisplayMailHtmlTemplateEdit" class="cerb-peek-trigger" data-context="{CerberusContexts::CONTEXT_MAIL_HTML_TEMPLATE}" data-context-id="{$page_context_id}" data-edit="true" title="{'common.edit'|devblocks_translate|capitalize}"><span class="glyphicons glyphicons-cogwheel"></span></button>
		{/if}
	</form>
	
	{if $pref_keyboard_shortcuts}
		<small>
		{$translate->_('common.keyboard')|lower}:
		(<b>e</b>) {'common.edit'|devblocks_translate|lower}
		(<b>1-9</b>) change tab
		</small>
	{/if}
</div>

<fieldset class="properties">
	<legend>{'HTML Template'|devblocks_translate|capitalize}</legend>

	<div style="margin-left:15px;">
	{foreach from=$properties item=v key=k name=props}
		<div class="property">
			{if $k == '...'}
				<b>{$translate->_('...')|capitalize}:</b>
				...
			{else}
				{include file="devblocks:cerberusweb.core::internal/custom_fields/profile_cell_renderer.tpl"}
			{/if}
		</div>
		{if $smarty.foreach.props.iteration % 3 == 0 && !$smarty.foreach.props.last}
			<br clear="all">
		{/if}
	{/foreach}
	<br clear="all">
	</div>
</fieldset>

{include file="devblocks:cerberusweb.core::internal/custom_fieldsets/profile_fieldsets.tpl" properties=$properties_custom_fieldsets}

{include file="devblocks:cerberusweb.core::internal/profiles/profile_record_links.tpl" properties=$properties_links}

<div>
{include file="devblocks:cerberusweb.core::internal/notifications/context_profile.tpl" context=$page_context context_id=$page_context_id}
</div>

<div>
{include file="devblocks:cerberusweb.core::internal/macros/behavior/scheduled_behavior_profile.tpl" context=$page_context context_id=$page_context_id}
</div>

<div id="profileMailHtmlTemplateTabs">
	<ul>
		{$tabs = [activity,comments]}

		<li><a href="{devblocks_url}ajax.php?c=internal&a=showTabActivityLog&scope=target&point={$point}&context={$page_context}&context_id={$page_context_id}{/devblocks_url}">{'common.log'|devblocks_translate|capitalize}</a></li>
		<li><a href="{devblocks_url}ajax.php?c=internal&a=showTabContextComments&point={$point}&context={$page_context}&id={$page_context_id}{/devblocks_url}">{$translate->_('common.comments')|capitalize} <div class="tab-badge">{DAO_Comment::count($page_context, $page_context_id)|default:0}</div></a></li>

		{foreach from=$tab_manifests item=tab_manifest}
			{$tabs[] = $tab_manifest->params.uri}
			<li><a href="{devblocks_url}ajax.php?c=profiles&a=showTab&ext_id={$tab_manifest->id}&point={$point}&context={$page_context}&context_id={$page_context_id}{/devblocks_url}"><i>{$tab_manifest->params.title|devblocks_translate}</i></a></li>
		{/foreach}
	</ul>
</div>
<br>

<script type="text/javascript">
	$(function() {
		var tabOptions = Devblocks.getDefaultjQueryUiTabOptions();
		tabOptions.active = Devblocks.getjQueryUiTabSelected('profileMailHtmlTemplateTabs');
		
		var tabs = $("#profileMailHtmlTemplateTabs").tabs(tabOptions);
		
		// Edit
		
		$('#btnDisplayMailHtmlTemplateEdit')
			.cerbPeekTrigger()
			.on('cerb-peek-opened', function(e) {
			})
			.on('cerb-peek-saved', function(e) {
				e.stopPropagation();
				document.location.reload();
			})
			.on('cerb-peek-deleted', function(e) {
				document.location.href = '{devblocks_url}{/devblocks_url}';
				
			})
			.on('cerb-peek-closed', function(e) {
			})
			;
	});
</script>

<script type="text/javascript">
{if $pref_keyboard_shortcuts}
$(document).keypress(function(event) {
	if(event.altKey || event.ctrlKey || event.shiftKey || event.metaKey)
		return;
	
	if($(event.target).is(':input'))
		return;

	hotkey_activated = true;
	
	switch(event.which) {
		case 49:  // (1) tab cycle
		case 50:  // (2) tab cycle
		case 51:  // (3) tab cycle
		case 52:  // (4) tab cycle
		case 53:  // (5) tab cycle
		case 54:  // (6) tab cycle
		case 55:  // (7) tab cycle
		case 56:  // (8) tab cycle
		case 57:  // (9) tab cycle
		case 58:  // (0) tab cycle
			try {
				idx = event.which-49;
				$tabs = $("#profileMailHtmlTemplateTabs").tabs();
				$tabs.tabs('option', 'active', idx);
			} catch(ex) { }
			break;
		case 101:  // (E) edit
			try {
				$('#btnDisplayMailHtmlTemplateEdit').click();
			} catch(ex) { }
			break;
		case 109:  // (M) macros
			try {
				$('#btnDisplayMacros').click();
			} catch(ex) { }
			break;
		default:
			// We didn't find any obvious keys, try other codes
			hotkey_activated = false;
			break;
	}
	
	if(hotkey_activated)
		event.preventDefault();
});
{/if}
</script>

{include file="devblocks:cerberusweb.core::internal/profiles/profile_common_scripts.tpl"}