// Labels for the RCA tools and their choices (App\Enums\RcaTool, FishboneCategory,
// BarrierStatus, RootCauseType). The server validates against the same values.

export const RCA_TOOLS = [
    { value: 'simple', label: 'Findings', hint: 'Plain findings, without a formal tool.' },
    { value: 'timeline', label: 'Timeline', hint: 'What happened, in order. Flag the moments where something went wrong.' },
    { value: 'process_map', label: 'Process Map', hint: 'Each step as it should happen, next to what actually happened. Flag deviations.' },
    { value: 'fishbone', label: 'Fishbone', hint: 'Contributing causes, grouped under six categories.' },
    { value: 'five_whys', label: '5 Whys', hint: 'Keep asking "why?" until you reach the root cause.' },
    { value: 'barrier', label: 'Barrier Analysis', hint: 'The defenses that should have stopped this, and how each one performed.' },
];

export const FISHBONE_CATEGORIES = {
    people: 'People',
    methods: 'Methods/Procedures',
    equipment: 'Equipment',
    environment: 'Environment',
    materials: 'Materials/Medication',
    management: 'Management/Communication',
};

export const BARRIER_STATUSES = {
    worked: 'Worked',
    failed: 'Failed',
    missing: 'Missing',
    not_used: 'Not used',
};

export const ROOT_CAUSE_TYPES = {
    staffing: 'Staffing',
    procedure: 'Procedure',
    equipment: 'Equipment',
    environment: 'Environment',
    communication: 'Communication',
    other: 'Other',
};

export const emptyFinding = (tool) => ({
    tool,
    question: '',
    finding: '',
    group_name: '',
    occurred_at: '',
    is_flagged: false,
    is_root_cause: false,
    category: '',
});

// The cause type only belongs to root-cause findings.
export const withoutStrayCauseType = (data) => ({ ...data, category: data.is_root_cause ? data.category : '' });
