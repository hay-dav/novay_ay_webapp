<script setup>
import { computed } from 'vue';
import { CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Title, Tooltip, } from 'chart.js';
import { Line } from 'vue-chartjs';
ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend);
const props = defineProps({
    entries: { type: Array, required: true },
});
const chartData = computed(() => ({
    labels: props.entries.map((entry) => new Date(entry.measured_on).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })),
    datasets: [
        {
            label: 'Вес',
            data: props.entries.map((entry) => entry.weight_kg),
            borderColor: '#dbb8ff',
            backgroundColor: 'rgba(219, 184, 255, 0.2)',
            pointBackgroundColor: '#dbb8ff',
            pointBorderColor: '#141218',
            pointRadius: 5,
            tension: 0.42,
            fill: true,
        },
    ],
}));
const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
    },
    scales: {
        x: {
            grid: { color: 'rgba(151, 141, 157, 0.12)' },
            ticks: { color: '#cec3d3' },
        },
        y: {
            grid: { color: 'rgba(151, 141, 157, 0.12)' },
            ticks: { color: '#cec3d3' },
        },
    },
};
</script>

<template>
  <div class="h-[290px] rounded-[24px] border border-white/10 bg-surface-low p-4">
    <Line :data="chartData" :options="chartOptions" />
  </div>
</template>
