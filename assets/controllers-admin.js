import { startStimulusApp } from '@symfony/stimulus-bundle';
import ChartjsController from '@symfony/ux-chartjs';
import { Chart } from 'chart.js';
import GuidedProjectController from './js/guided-project.js';
import HealthCheckTableController from './js/health-check-table.js';
import OnboardingTourController from './js/onboarding-tour.js';

// Guards against this module's top-level code running twice, which would start a second Stimulus app
if (!window.__c975lConfigAdminStarted) {
    window.__c975lConfigAdminStarted = true;

    // Back-office controllers, loaded as their own module tag and starting their own Stimulus app
    const app = startStimulusApp();
    app.register('onboarding-tour', OnboardingTourController);
    app.register('guided-project', GuidedProjectController);
    app.register('health-check-table', HealthCheckTableController);
    // render_chart() emits this exact identifier, which this admin app never gets for free from the app's bootstrap
    app.register('symfony--ux-chartjs--chart', ChartjsController);

    // Safety net for "Canvas is already in use": each admin app registers whatever controllers.json enables
    // "chartjs:pre-connect" is ux-chartjs's own public event, fired on the canvas before its Chart()
    document.addEventListener('chartjs:pre-connect', (event) => {
        Chart.getChart(event.target)?.destroy();
    });
}
