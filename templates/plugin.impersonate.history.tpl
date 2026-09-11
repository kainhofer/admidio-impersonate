<p>{$l10n->get('PLG_IMPERSONATE_HISTORY_DESC')}</p>

{if count($impersonations) === 0}
    <div class="alert alert-info">{$l10n->get('PLG_IMPERSONATE_HISTORY_EMPTY')}</div>
{else}
    <div class="table-responsive">
        <table id="adm_plg_impersonate_table" class="table table-hover">
            <thead>
                <tr>
                    <th>{$l10n->get('PLG_IMPERSONATE_BEGIN')}</th>
                    <th>{$l10n->get('PLG_IMPERSONATE_END')}</th>
                    <th>{$l10n->get('PLG_IMPERSONATE_ADMINISTRATOR')}</th>
                    <th>{$l10n->get('PLG_IMPERSONATE_USER')}</th>
                    <th>{$l10n->get('PLG_IMPERSONATE_ENDED_BY')}</th>
                    <th>{$l10n->get('PLG_IMPERSONATE_IP_ADDRESS')}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $impersonations as $impersonation}
                    <tr>
                        <td>{$impersonation.begin}</td>
                        <td>{$impersonation.end}</td>
                        <td>{$impersonation.admin}</td>
                        <td>{$impersonation.target}</td>
                        <td>{$impersonation.status}</td>
                        <td>{$impersonation.ip}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}
