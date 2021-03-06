{$page_context = CerberusContexts::CONTEXT_KB_ARTICLE}
{$page_context_id = $article->id}
{$is_writeable = Context_KbArticle::isWriteableByActor($article, $active_worker)}

<h1>{$article->title}</h1>

<div class="cerb-profile-toolbar">
	<form class="toolbar" action="{devblocks_url}{/devblocks_url}" method="post" style="margin:5px 0px 5px 0px;">
		<input type="hidden" name="_csrf_token" value="{$session.csrf_token}">
		
		<span id="spanInteractions">
		{include file="devblocks:cerberusweb.core::events/interaction/interactions_menu.tpl"}
		</span>
		
		<!-- Card -->
		<button type="button" id="btnProfileCard" title="{'common.card'|devblocks_translate|capitalize}" data-context="{$page_context}" data-context-id="{$page_context_id}"><span class="glyphicons glyphicons-nameplate"></span></button>
		
		<!-- Edit -->
		{if $is_writeable && $active_worker->hasPriv("contexts.{$page_context}.update")}
		<button id="btnDisplayKbEdit" type="button" data-context="{$page_context}" data-context-id="{$page_context_id}" data-edit="true" title="{'common.edit'|devblocks_translate|capitalize} (E)"><span class="glyphicons glyphicons-cogwheel"></span></button>
		{/if}
	</form>
	
	{if $pref_keyboard_shortcuts}
	<small>
		{'common.keyboard'|devblocks_translate|lower}:
		{if $active_worker->hasPriv('contexts.cerberusweb.contexts.kb_article.update')}(<b>e</b>) {'common.edit'|devblocks_translate|lower}{/if}
		(<b>1-9</b>) change tab 
	</small> 
	{/if}
</div>

<fieldset class="properties">
	<legend>Knowledgebase Article</legend>
	
	<div style="margin-left:15px;">
	{foreach from=$properties item=v key=k name=props}
		<div class="property">
			{if $k == '...'}
				<b>{'...'|devblocks_translate|capitalize}:</b>
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
		
	{if !empty($breadcrumbs)}
	<b>Filed under:</b> 
	{foreach from=$breadcrumbs item=trail name=trail}
		{foreach from=$trail item=step key=cat_id name=cats}
		<span>{$categories.{$cat_id}->name}</span>
		{if !$smarty.foreach.cats.last} &raquo; {/if}
		{/foreach}
		{if !$smarty.foreach.trail.last}; {/if}
	{/foreach}
	<br clear="all">
	{/if}
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

<div id="profileKbArticleTabs">
	<ul>
		{$tabs = [article,activity,comments]}

		<li><a href="{devblocks_url}ajax.php?c=profiles&a=handleSectionAction&section=kb&action=showArticleTab&point={$point}&context={$page_context}&context_id={$page_context_id}{/devblocks_url}">{'common.article'|devblocks_translate|capitalize}</a></li>
		<li><a href="{devblocks_url}ajax.php?c=internal&a=showTabActivityLog&scope=target&point={$point}&context={$page_context}&context_id={$page_context_id}{/devblocks_url}">{'common.log'|devblocks_translate|capitalize}</a></li>
		<li><a href="{devblocks_url}ajax.php?c=internal&a=showTabContextComments&point={$point}&context={$page_context}&point={$point}&id={$page_context_id}{/devblocks_url}">{'common.comments'|devblocks_translate|capitalize} <div class="tab-badge">{DAO_Comment::count($page_context, $page_context_id)|default:0}</div></a></li>

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
	tabOptions.active = Devblocks.getjQueryUiTabSelected('profileKbArticleTabs');
	
	// Tabs
	
	var tabs = $("#profileKbArticleTabs").tabs(tabOptions);
	
	// Page title
	
	document.title = "KB - {$article->title|escape:'javascript' nofilter} - {$settings->get('cerberusweb.core','helpdesk_title')|escape:'javascript' nofilter}";
	
	$('#btnProfileCard').cerbPeekTrigger();
	
	// Edit
	{if $is_writeable && $active_worker->hasPriv("contexts.{$page_context}.update")}
	$('#btnDisplayKbEdit')
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
	{/if}
	
	// Interactions
	var $interaction_container = $('#spanInteractions');
	{include file="devblocks:cerberusweb.core::events/interaction/interactions_menu.js.tpl"}
});

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
				$tabs = $("#profileKbArticleTabs").tabs();
				$tabs.tabs('option', 'active', idx);
			} catch(ex) { } 
			break;
		{if $active_worker->hasPriv('contexts.cerberusweb.contexts.kb_article.update')}
		case 101:  // (E) edit
			try {
				$('#btnDisplayKbEdit').click();
			} catch(ex) { } 
			break;
		{/if}
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