import { isAdmin, isSuperAdmin } from '../../lib/roles';

// Rail items by role. Navigation depends on role for convenience only; the server enforces every permission.
// Related pages live as tabs inside one item (Attendance: Mine/Team, People: People/Departments, Agents: Agents/Usage/Prices,
// Settings: Settings/Health/Updates).
export function railItems(user) {
    const items = [
        { id: '/chat', label: 'Chat', icon: 'chat' },
        { id: '/attendance', label: 'Attendance', icon: 'clock' },
        { id: '/scores', label: 'Scores', icon: 'star', title: 'My prompt scores' },
    ];
    if (isAdmin(user)) {
        items.push(
            { id: '/reports', label: 'Reports', icon: 'layers' },
            { id: '/people', label: 'People', icon: 'users' },
            { id: '/agents', label: 'Agents', icon: 'chip', title: 'AI agents' },
        );
    }
    if (isSuperAdmin(user)) items.push({ id: '/settings', label: 'Settings', icon: 'sliders' });
    return items;
}

// Old bookmarks: each old URL now opens the matching tab.
export const LEGACY = {
    '/team-attendance': '/attendance/team',
    '/departments': '/people/departments',
    '/system': '/settings/health',
    '/providers': '/agents',
    '/reports/topics': '/reports/tasks', // the topic list moved onto the Tasks & topics tab
};

export const isActive = (path, id) => path === id || path.startsWith(`${id}/`);
