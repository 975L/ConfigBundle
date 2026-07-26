import { startStimulusApp } from '@symfony/stimulus-bundle';
import ChartjsController from '@symfony/ux-chartjs';
import { Chart } from 'chart.js';
import HealthCheckTableController from './js/health-check-table.js';
import OnboardingTourController from './js/onboarding-tour.js';

// Guards against this module's top-level code running more than once per page (eg. this <script type="module">
// tag getting re-inserted/re-executed for any reason) - without this, a second run creates a brand new
// Stimulus Application that immediately tries to register every controller below a second time
if (!window.__c975lConfigAdminStarted) {
    window.__c975lConfigAdminStarted = true;

    // Back-office controllers, used only in EasyAdmin. Loaded as its own <script type="module"> tag (see
    // importmap.php), starts its own Stimulus app - same pattern as c975l/ui-bundle's own admin.js
    const app = startStimulusApp();
    app.register('onboarding-tour', OnboardingTourController);
    app.register('health-check-table', HealthCheckTableController);
    // render_chart() (see HealthCheckTrendChartBuilder) renders data-controller="symfony--ux-chartjs--chart" -
    // registered under that exact identifier since this admin app never gets it "for free" from the consuming
    // app's own assets/bootstrap.js (which the /management dashboard deliberately doesn't load, see README)
    app.register('symfony--ux-chartjs--chart', ChartjsController);

    // Safety net for "Canvas is already in use": every bundle's controllers-admin.js starts its own Stimulus
    // app, and each startStimulusApp() eagerly registers whatever assets/controllers.json enables - so an app
    // leaving ux-chartjs enabled there gets one `new Chart()` per admin entry on the same <canvas>. The fix is
    // "enabled": false in the app's controllers.json (see readme, and c975l:config:check-importmap warns about
    // it), this only keeps an app that hasn't done it yet from crashing. "chartjs:pre-connect" is ux-chartjs's
    // own public event, dispatched on the canvas right before its `new Chart()` call, see its controller.js
    document.addEventListener('chartjs:pre-connect', (event) => {
        Chart.getChart(event.target)?.destroy();
    });
}
