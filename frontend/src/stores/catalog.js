import { defineStore } from 'pinia';
import { api } from '@/services/api';
export const useCatalogStore = defineStore('catalog', {
    state: () => ({
        courses: [],
        materials: [],
        loading: false,
    }),
    actions: {
        async fetchCourses() {
            this.loading = true;
            try {
                const [{ data: courseData }, { data: materialData }] = await Promise.all([
                    api.get('/courses'),
                    api.get('/course-materials'),
                ]);
                this.courses = courseData.data;
                this.materials = materialData.data;
            }
            finally {
                this.loading = false;
            }
        },
        async purchase(courseId) {
            const { data } = await api.post(`/courses/${courseId}/purchase`);
            return data;
        },
        async addMaterial(payload) {
            const formData = new FormData();
            formData.append('title', payload.title);
            formData.append('description', payload.description);
            if (payload.file) {
                formData.append('file', payload.file);
            }
            const { data } = await api.post('/course-materials', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            await this.fetchCourses();
            return data;
        },
    },
});
