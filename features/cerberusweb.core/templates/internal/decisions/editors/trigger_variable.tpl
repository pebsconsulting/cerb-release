{if $var.type == 'S'}
{$var_type_label = 'Text'}
{elseif $var.type == 'D'}
{$var_type_label = 'Picklist'}
{elseif $var.type == 'N'}
{$var_type_label = 'Number'}
{elseif $var.type == 'E'}
{$var_type_label = 'Date'}
{elseif $var.type == 'C'}
{$var_type_label = 'True/False'}
{elseif $var.type == 'W'}
{$var_type_label = 'Worker'}
{elseif substr($var.type,0,4)=='ctx_'}
	{$list_context_ext = substr($var.type,4)}
	{$list_context = $list_contexts.$list_context_ext}
	{$var_type_label = "(List) {$list_context->name}"}
{/if}
	
<fieldset class="peek">
	<legend style="cursor:move;"><a href="javascript:;" onclick="$(this).closest('fieldset').remove();"><span class="cerb-sprite2 sprite-minus-circle" style="vertical-align:middle;"></span></a> {$var_type_label}</legend>
	<input type="hidden" name="var[]" value="{$seq}">
	<input type="hidden" name="var_key[]" value="{$var.key}">
	<input type="hidden" name="var_type[]" value="{$var.type}">

	<div>
		<select name="var_is_private[]">
			<option value="1" {if $var.is_private}selected="selected"{/if}>private</option>
			<option value="0" {if empty($var.is_private)}selected="selected"{/if}>public</option>
		</select>
		<input type="text" name="var_label[]" value="{$var.label}" size="64" placeholder="Variable name">
	</div>
	
	<div style="margin-left:10px;">
	{if $var.type == 'S'}
	<div>
		<label><input type="radio" name="var_params{$seq}[widget]" value="single" {if $var.params.widget=='single'}checked="checked"{/if}> Single line</label>
		<label><input type="radio" name="var_params{$seq}[widget]" value="multiple" {if $var.params.widget=='multiple'}checked="checked"{/if}> Multiple lines</label>
	</div>
	{elseif $var.type == 'D'}
	{elseif $var.type == 'N'}
	{elseif $var.type == 'E'}
	{elseif $var.type == 'C'}
	{elseif $var.type == 'W'}
	{elseif substr($var.type,0,4)=='ctx_'}
	{/if}
	</div>
</fieldset>
