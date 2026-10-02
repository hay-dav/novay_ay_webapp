import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';

export function usePaidMaterialAccess() {
    const auth = useAuthStore();
    const requiresPaidAccess = computed(() => auth.user?.role === 'client' && auth.user?.access_status !== 'paid');
    return { requiresPaidAccess };
}
