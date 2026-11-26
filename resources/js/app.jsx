import '../css/app.css';
import { useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { AuthProvider, useAuth } from './lib/auth';
import { RouterProvider, useRouter } from './lib/router';
import { BrandingProvider } from './lib/branding';
import { ToastProvider } from './components/ui/Feedback';
import { Skeleton } from './components/ui/Feedback';
import { Card } from './components/ui/Layout';
import { Alert } from './components/ui/Feedback';
import { Button } from './components/ui/Button';
import MaintenanceNotice from './components/MaintenanceNotice';
import WorkspaceShell from './components/shell/WorkspaceShell';
import Section from './components/shell/Section';
import { PageBody } from './components/shell/Page';
import { LEGACY } from './components/shell/nav';
import Account from './pages/Account';
import Attendance from './pages/Attendance';
import TeamAttendance from './pages/TeamAttendance';
import ComponentGallery from './pages/ComponentGallery';
import Chat from './pages/Chat';
import Departments from './pages/Departments';
import SettingsArea, { SETTINGS_ITEMS } from './pages/SettingsArea';
import InstallWizard from './pages/InstallWizard';
import ChangePassword from './pages/ChangePassword';
import Login from './pages/Login';
import People from './pages/People';
import { Prices, Usage } from './pages/AiAgents';
import AgentsPanel from './pages/AgentsPanel';
import PolicyAck from './pages/PolicyAck';
import TokenGallery from './pages/TokenGallery';
import AnswerGallery from './pages/AnswerGallery';
import Reports from './pages/Reports';
import MyScores from './pages/MyScores';

const ADMIN_ONLY = ['/people', '/agents', '/settings', '/attendance/team', '/reports'];
const under = (path, base) => path === base || path.startsWith(`${base}/`);

// The tab sets for each rail item. The server decides who may use the data; this only decides what is shown.
const sections = (user) => {
    const admin = user.role === 'admin' || user.role === 'super_admin';
    const sup = user.role === 'super_admin';
    return {
        '/reports': admin && { title: 'Reports', preserveSearch: true, tabs: [
            { id: 'overview', path: '/reports', label: 'Overview', node: <Reports view="overview" /> },
            { id: 'usage', path: '/reports/usage', label: 'Usage & cost', node: <Reports view="usage" /> },
            { id: 'quality', path: '/reports/quality', label: 'Quality', node: <Reports view="quality" /> },
            { id: 'adoption', path: '/reports/adoption', label: 'Adoption', node: <Reports view="adoption" /> },
            { id: 'tasks', path: '/reports/tasks', label: 'Tasks & topics', node: <Reports view="tasks" /> },
            { id: 'prompts', path: '/reports/prompts', label: 'Prompt quality', node: <Reports view="prompts" /> },
            { id: 'attendance', path: '/reports/attendance', label: 'Attendance', node: <Reports view="attendance" /> },
            { id: 'people', path: '/reports/people', label: 'People', node: <Reports view="people" /> },
            { id: 'conversations', path: '/reports/conversations', label: 'Conversations', node: <Reports view="conversations" /> },
            { id: 'audit', path: '/reports/audit', label: 'Audit', node: <Reports view="audit" /> },
        ] },
        '/scores': { title: 'My prompt scores', tabs: [{ id: 'scores', path: '/scores', label: 'My prompt scores', node: <MyScores /> }] },
        '/attendance': { title: 'Attendance', tabs: [{ id: 'mine', path: '/attendance', label: 'Mine', node: <Attendance /> }, ...(admin ? [{ id: 'team', path: '/attendance/team', label: 'Team', node: <TeamAttendance /> }] : [])] },
        '/people': admin && { title: 'People', tabs: [{ id: 'people', path: '/people', label: 'People', node: <People /> }, { id: 'departments', path: '/people/departments', label: 'Departments', node: <Departments /> }] },
        '/agents': admin && { title: 'AI agents', tabs: [
            { id: 'agents', path: '/agents', label: 'Agents', node: <><ViewOnly show={!sup} /><AgentsPanel canManage={sup} /></> },
            { id: 'usage', path: '/agents/usage', label: 'Usage', node: <Usage /> },
            { id: 'prices', path: '/agents/prices', label: 'Prices', node: <><ViewOnly show={!sup} /><Prices canManage={sup} /></> },
        ] },
        '/settings': sup && { title: 'Settings', area: true, paths: SETTINGS_ITEMS.map((i) => i.path), tabs: [] },
        '/account': { title: 'Account', tabs: [{ id: 'account', path: '/account', label: 'Account', node: <Account /> }] },
    };
};

function ViewOnly({ show }) {
    return show ? <Alert tone="info" title="View only">Only a Super Admin can change agents or prices.</Alert> : null;
}

function Screen() {
    const { path, navigate } = useRouter();
    const { user } = useAuth();
    // New code was deployed and the database is behind it: the Super Admin goes straight to the update screen.
    const dbUpdate = user.database_update_needed && !path.startsWith('/settings/updates') ? '/settings/updates' : null;
    const redirect = dbUpdate ?? (path === '/' ? '/chat' : LEGACY[path]);

    // Chat is the home screen, and old URLs open the matching tab.
    useEffect(() => { if (redirect) navigate(redirect, { replace: true }); }, [redirect, navigate]);
    if (redirect) return null;

    if (path === '/chat' || path.startsWith('/chat/')) return <Chat />;
    const base = Object.keys(sections(user)).find((b) => under(path, b));
    const section = base && sections(user)[base];
    // A tab path the person may not use (for example /attendance/team for an employee) is treated as no access.
    if (section?.area && section.paths.includes(path)) return <SettingsArea />;
    if (section && section.tabs.some((t) => t.path === path)) return <Section title={section.title} tabs={section.tabs} preserveSearch={section.preserveSearch} />;

    const denied = ADMIN_ONLY.some((b) => under(path, b));
    return (
        <PageBody>
            <Card title={denied ? 'You do not have access to this page' : 'Page not found'}>
                <p>{denied ? 'Ask a Super Admin if you need it.' : 'The page you asked for does not exist.'}</p>
                <div><Button variant="soft" size="sm" onClick={() => navigate('/chat')}>Go to chat</Button></div>
            </Card>
        </PageBody>
    );
}

function Gate() {
    const { status, user } = useAuth();
    const { path } = useRouter();

    // Temporary developer galleries; remove before launch.
    if (path === '/design/components') return <ComponentGallery />;
    if (path === '/design/tokens') return <TokenGallery />;
    if (path === '/design/answer') return <AnswerGallery />;
    if (path === '/design/install') return <InstallWizard />; // look of the installer on an installed site (its calls are refused)

    if (status === 'loading') return <main className="mx-auto max-w-[440px] px-6 py-20"><Skeleton lines={4} /></main>;
    if (status === 'guest') return <Login />;
    if (user.must_change_password) return <ChangePassword />;
    if (!user.policy_acknowledged) return <PolicyAck />;
    return <WorkspaceShell><Screen /></WorkspaceShell>;
}

function App() {
    // Not installed: the wizard is the only screen (the server also blocks the rest of the API).
    if (!window.__APP__?.installed) return <InstallWizard />;
    return (
        <RouterProvider>
            <BrandingProvider>
                <ToastProvider>
                    <AuthProvider><Gate /></AuthProvider>
                    <MaintenanceNotice />
                </ToastProvider>
            </BrandingProvider>
        </RouterProvider>
    );
}

createRoot(document.getElementById('root')).render(<App />);
