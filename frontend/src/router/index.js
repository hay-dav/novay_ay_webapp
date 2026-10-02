import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import LandingView from '@/views/LandingView.vue';
import LoginView from '@/views/LoginView.vue';
import PrivacyPolicyView from '@/views/PrivacyPolicyView.vue';

const DashboardView = () => import('@/views/DashboardView.vue');
const CatalogView = () => import('@/views/CatalogView.vue');
const ProgressView = () => import('@/views/ProgressView.vue');
const WorkoutsView = () => import('@/views/WorkoutsView.vue');
const ExpertLivesView = () => import('@/views/ExpertLivesView.vue');
const ChatView = () => import('@/views/ChatView.vue');
const AdminView = () => import('@/views/AdminView.vue');
const ParticipantsView = () => import('@/views/ParticipantsView.vue');
const ArticleLessonsView = () => import('@/views/ArticleLessonsView.vue');
const PodcastsView = () => import('@/views/PodcastsView.vue');
const AccessManagementView = () => import('@/views/AccessManagementView.vue');
const TariffsView = () => import('@/views/TariffsView.vue');
const GuestLiveView = () => import('@/views/GuestLiveView.vue');
const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'landing', component: LandingView },
        { path: '/login', name: 'login', component: LoginView },
        { path: '/privacy-policy', name: 'privacy-policy', component: PrivacyPolicyView },
        { path: '/live/:token', name: 'guest-live', component: GuestLiveView },
        { path: '/app', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true } },
        { path: '/catalog', name: 'catalog', component: CatalogView, meta: { requiresAuth: true } },
        { path: '/courses/:slug', name: 'course', redirect: '/catalog', meta: { requiresAuth: true } },
        { path: '/progress', name: 'progress', component: ProgressView, meta: { requiresAuth: true } },
        { path: '/tariffs', name: 'tariffs', component: TariffsView, meta: { requiresAuth: true, clientOnly: true } },
        { path: '/favorites', name: 'favorites', component: WorkoutsView, props: { section: 'workouts', favoritesOnly: true }, meta: { requiresAuth: true, clientOnly: true } },
        { path: '/workouts', name: 'workouts', component: WorkoutsView, props: { section: 'workouts' }, meta: { requiresAuth: true } },
        { path: '/expert-lives', name: 'expert-lives', component: ExpertLivesView, meta: { requiresAuth: true } },
        { path: '/lessons', name: 'article-lessons', component: ArticleLessonsView, props: { section: 'lessons' }, meta: { requiresAuth: true } },
        { path: '/recipes', name: 'recipes', component: ArticleLessonsView, props: { section: 'recipes' }, meta: { requiresAuth: true } },
        { path: '/news', name: 'news', component: ArticleLessonsView, props: { section: 'news' }, meta: { requiresAuth: true } },
        { path: '/knowledge-base', name: 'knowledge-base', component: ArticleLessonsView, props: { section: 'knowledge' }, meta: { requiresAuth: true } },
        { path: '/podcasts', name: 'podcasts', component: PodcastsView, meta: { requiresAuth: true } },
        { path: '/chat', name: 'chat', component: ChatView, meta: { requiresAuth: true } },
        { path: '/participants', name: 'participants', component: ParticipantsView, meta: { requiresAuth: true, staffOnly: true } },
        { path: '/access-management', name: 'access-management', component: AccessManagementView, meta: { requiresAuth: true, accessManagerOnly: true } },
        { path: '/admin', name: 'admin', component: AdminView, meta: { requiresAuth: true, adminOnly: true } },
    ],
});
router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (auth.token && !auth.user) {
        await auth.fetchMe().catch(() => undefined);
    }
    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login' };
    }
    // A saved token is restored above, so an already signed-in user never sees
    // the login form when opening the personal account again.
    if (to.name === 'login' && auth.user) {
        return { name: 'dashboard' };
    }
    if (to.meta.staffOnly && !auth.isStaff) {
        return { name: 'dashboard' };
    }
    if (to.meta.clientOnly && auth.isStaff) {
        return { name: 'dashboard' };
    }
    if (to.meta.adminOnly && !auth.isAdmin) {
        return { name: 'dashboard' };
    }
    if (to.meta.accessManagerOnly && !['admin', 'curator'].includes(auth.user?.role ?? '')) {
        return { name: 'dashboard' };
    }
    return true;
});
export default router;
