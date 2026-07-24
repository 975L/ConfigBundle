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

    // EasyAdmin sets data-turbo="false" on <html> (see its own layout.html.twig), so Turbo Drive never
    // intercepts navigation here - whatever causes Stimulus to call connect() a second time on the same
    // <canvas> without an intervening disconnect() isn't a Turbo caching/morph issue. Rather than chase that
    // exact trigger, this destroys any chart already attached to a canvas right before ux-chartjs's own
    // controller creates a new one on it - "chartjs:pre-connect" is ux-chartjs's own public event, dispatched
    // on the canvas itself right before its `new Chart()` call, see vendor/symfony/ux-chartjs's controller.js
    document.addEventListener('chartjs:pre-connect', (event) => {
        Chart.getChart(event.target)?.destroy();
    });
}
