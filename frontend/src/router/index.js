import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import LandingView from '@/views/LandingView.vue';
import LoginView from '@/views/LoginView.vue';
import PrivacyPolicyView from '@/views/PrivacyPolicyView.vue';

const DashboardView = () => import('@/views/DashboardView.vue');
const CatalogView = () => import('@/views/CatalogView.vue');
const CourseView = () => import('@/views/CourseView.vue');
const ProgressView = () => import('@/views/ProgressView.vue');
const NutritionView = () => import('@/views/NutritionView.vue');
const TrainerView = () => import('@/views/TrainerView.vue');
const WorkoutsView = () => import('@/views/WorkoutsView.vue');
const ChatView = () => import('@/views/ChatView.vue');
const AdminView = () => import('@/views/AdminView.vue');
const ParticipantsView = () => import('@/views/ParticipantsView.vue');
const ArticleLessonsView = () => import('@/views/ArticleLessonsView.vue');
const PodcastsView = () => import('@/views/PodcastsView.vue');
// Keep the nutrition module in place for a future release.
const nutritionFeatureEnabled = false;
const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'landing', component: LandingView },
        { path: '/login', name: 'login', component: LoginView },
        { path: '/privacy-policy', name: 'privacy-policy', component: PrivacyPolicyView },
        { path: '/app', name: 'dashboard', component: DashboardView, meta: { requiresAuth: true } },
        { path: '/catalog', name: 'catalog', component: CatalogView, meta: { requiresAuth: true } },
        { path: '/courses/:slug', name: 'course', component: CourseView, meta: { requiresAuth: true } },
        { path: '/progress', name: 'progress', component: ProgressView, meta: { requiresAuth: true } },
        { path: '/nutrition', name: 'nutrition', component: NutritionView, meta: { requiresAuth: true, nutritionFeature: true } },
        { path: '/workouts', name: 'workouts', component: WorkoutsView, meta: { requiresAuth: true } },
        { path: '/lessons', name: 'article-lessons', component: ArticleLessonsView, meta: { requiresAuth: true } },
        { path: '/podcasts', name: 'podcasts', component: PodcastsView, meta: { requiresAuth: true } },
        { path: '/chat', name: 'chat', component: ChatView, meta: { requiresAuth: true } },
        { path: '/participants', name: 'participants', component: ParticipantsView, meta: { requiresAuth: true, trainerOnly: true } },
        { path: '/trainer', name: 'trainer', component: TrainerView, meta: { requiresAuth: true, trainerOnly: true } },
        { path: '/admin', name: 'admin', component: AdminView, meta: { requiresAuth: true, adminOnly: true } },
    ],
});
router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (auth.token && !auth.user) {
        await auth.fetchMe().catch(() => auth.logout());
    }
    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login' };
    }
    if (to.meta.nutritionFeature && !nutritionFeatureEnabled) {
        return { name: 'dashboard' };
    }
    // A saved token is restored above, so an already signed-in user never sees
    // the login form when opening the personal account again.
    if (to.name === 'login' && auth.user) {
        return { name: 'dashboard' };
    }
    if (to.meta.trainerOnly && !auth.isTrainer) {
        return { name: 'dashboard' };
    }
    if (to.meta.adminOnly && !auth.isAdmin) {
        return { name: 'dashboard' };
    }
    return true;
});
export default router;
