/**
 * Curated MDI icon set for Decision categories.
 *
 * Each entry is { name, label } where name is the PascalCase component name
 * matching the vue-material-design-icons file (e.g. 'Cog' → Cog.vue).
 * Both ManageTeamView (picker) and TeamDecisionsView (landing grid) import
 * this list and build a component map from it.
 *
 * To add icons: add an entry here AND import the .vue file in both consumers.
 */
export const CATEGORY_ICONS = [
    { name: 'Briefcase',             label: 'Business' },
    { name: 'AccountGroup',          label: 'People' },
    { name: 'ChartBar',              label: 'Analytics' },
    { name: 'Lightbulb',             label: 'Ideas' },
    { name: 'Rocket',                label: 'Launch' },
    { name: 'Cog',                   label: 'Settings' },
    { name: 'Shield',                label: 'Security' },
    { name: 'Database',              label: 'Data' },
    { name: 'Earth',                 label: 'Global' },
    { name: 'FileDocumentOutline',   label: 'Documents' },
    { name: 'Gavel',                 label: 'Legal' },
    { name: 'Tag',                   label: 'Tags' },
    { name: 'Star',                  label: 'Priority' },
    { name: 'Flag',                  label: 'Milestones' },
    { name: 'Cash',                  label: 'Finance' },
    { name: 'School',                label: 'Learning' },
    { name: 'Wrench',                label: 'Engineering' },
    { name: 'Bug',                   label: 'Issues' },
    { name: 'CheckCircleOutline',    label: 'Completed' },
    { name: 'AlertCircleOutline',    label: 'Risk' },
    { name: 'ClockOutline',          label: 'Timeline' },
    { name: 'Map',                   label: 'Roadmap' },
    { name: 'MessageOutline',        label: 'Comms' },
    { name: 'Lock',                  label: 'Compliance' },
    { name: 'CalendarCheck',         label: 'Planning' },
    { name: 'TrendingUp',            label: 'Growth' },
    { name: 'Cloud',                 label: 'Platform' },
    { name: 'CodeBraces',            label: 'Development' },
    { name: 'HeartOutline',          label: 'Values' },
    { name: 'Handshake',             label: 'Partnerships' },
]
