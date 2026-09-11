<form {foreach $attributes as $attribute}
        {$attribute@key}="{$attribute}"
    {/foreach}>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.select.tpl' data=$elements['impersonate_roles']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['impersonate_targets_subset_only']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['impersonate_max_minutes']}
    {include 'sys-template-parts/form.checkbox.tpl' data=$elements['impersonate_notify_user']}
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save_impersonate']}

    <div class="form-alert" style="display: none;">&nbsp;</div>
</form>
<p class="mt-3">
    <a href="{$impersonateHistoryUrl}"><i class="bi bi-clock-history"></i> {$l10n->get('PLG_IMPERSONATE_HISTORY')}</a>
</p>
{$javascript}
