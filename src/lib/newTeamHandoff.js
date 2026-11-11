/**
 * A successful create should land on the new team's dashboard rather than
 * leaving the creator in the wizard. Keep the success/recovery screen only
 * when it has information the creator must act on, or the creator has handed
 * ownership over and left the team.
 *
 * The layout is intentionally NOT calculated here: TeamView loads the normal
 * template -> personal type/team -> enforced team layout cascade on mount.
 */
export function shouldOpenCreatedTeam({
    team,
    creatorLeftTeam = false,
    templateError = '',
    serviceClaimError = '',
    roleResult = null,
    progressTasks = [],
    provisioningState = null,
} = {}) {
    if (!team?.id || creatorLeftTeam || templateError || serviceClaimError) return false
    if (roleResult?.owner?.status === 'failed') return false
    if (Object.values(roleResult?.levels || {}).some(result => result !== 'ok')) return false
    if (progressTasks.some(task => task.status === 'error')) return false
    if (provisioningState && provisioningState.status !== 'completed') return false
    return true
}
