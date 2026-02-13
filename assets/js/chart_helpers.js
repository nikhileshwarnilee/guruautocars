(function (window, document) {
  'use strict';

  var CHART_SCRIPT_URL = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
  var chartLoaderPromise = null;

  var palette = {
    blue: '#0d6efd',
    indigo: '#6610f2',
    purple: '#6f42c1',
    pink: '#d63384',
    red: '#dc3545',
    orange: '#fd7e14',
    yellow: '#ffc107',
    green: '#198754',
    teal: '#20c997',
    cyan: '#0dcaf0',
    slate: '#6c757d',
    dark: '#343a40'
  };

  function resolveElement(ref) {
    if (!ref) {
      return null;
    }
    if (typeof ref === 'string') {
      return document.querySelector(ref);
    }
    return ref.nodeType === 1 ? ref : null;
  }

  function ensureChartJs() {
    if (window.Chart) {
      return Promise.resolve(window.Chart);
    }
    if (chartLoaderPromise) {
      return chartLoaderPromise;
    }

    chartLoaderPromise = new Promise(function (resolve, reject) {
      var existing = document.querySelector('script[data-gac-chartjs="1"]');
      if (existing) {
        existing.addEventListener('load', function () {
          resolve(window.Chart || null);
        });
        existing.addEventListener('error', function () {
          reject(new Error('Unable to load Chart.js'));
        });
        return;
      }

      var script = document.createElement('script');
      script.src = CHART_SCRIPT_URL;
      script.async = true;
      script.defer = true;
      script.setAttribute('data-gac-chartjs', '1');
      script.onload = function () {
        if (!window.Chart) {
          reject(new Error('Chart.js loaded without Chart global'));
          return;
        }
        resolve(window.Chart);
      };
      script.onerror = function () {
        reject(new Error('Unable to load Chart.js'));
      };
      document.head.appendChild(script);
    });

    return chartLoaderPromise;
  }

  function colorScale() {
    return [
      palette.blue,
      palette.green,
      palette.orange,
      palette.red,
      palette.cyan,
      palette.purple,
      palette.yellow,
      palette.pink,
      palette.indigo,
      palette.teal,
      palette.slate,
      palette.dark
    ];
  }

  function pickColors(count) {
    var source = colorScale();
    var out = [];
    for (var index = 0; index < count; index++) {
      out.push(source[index % source.length]);
    }
    return out;
  }

  function hasNumericPoint(value) {
    return typeof value === 'number' && isFinite(value);
  }

  function datasetHasData(dataset) {
    if (!dataset || !Array.isArray(dataset.data)) {
      return false;
    }
    for (var i = 0; i < dataset.data.length; i++) {
      if (hasNumericPoint(dataset.data[i])) {
        return true;
      }
      if (dataset.data[i] && typeof dataset.data[i] === 'object') {
        if (hasNumericPoint(dataset.data[i].y) || hasNumericPoint(dataset.data[i].value)) {
          return true;
        }
      }
    }
    return false;
  }

  function dataHasContent(config) {
    if (!config || !config.data || !Array.isArray(config.data.datasets) || config.data.datasets.length === 0) {
      return false;
    }
    for (var i = 0; i < config.data.datasets.length; i++) {
      if (datasetHasData(config.data.datasets[i])) {
        return true;
      }
    }
    return false;
  }

  function findOrCreateEmptyState(canvas, message) {
    if (!canvas) {
      return null;
    }
    var wrap = canvas.closest('.gac-chart-wrap');
    if (!wrap) {
      return null;
    }

    var emptyNode = wrap.querySelector('.gac-chart-empty');
    if (!emptyNode) {
      emptyNode = document.createElement('div');
      emptyNode.className = 'gac-chart-empty d-none';
      wrap.appendChild(emptyNode);
    }
    emptyNode.textContent = message || 'No chart data for the selected filters.';
    return emptyNode;
  }

  function setEmptyState(canvas, shouldShow, message) {
    var emptyNode = findOrCreateEmptyState(canvas, message);
    if (!emptyNode) {
      return;
    }
    emptyNode.classList.toggle('d-none', !shouldShow);
  }

  function createRegistry(namespace) {
    var key = String(namespace || 'gac-default');
    var charts = {};

    function destroyChart(chartKey) {
      if (!charts[chartKey]) {
        return;
      }
      try {
        charts[chartKey].destroy();
      } catch (error) {
        // Ignore stale chart instances.
      }
      delete charts[chartKey];
    }

    return {
      render: function (canvasRef, config, options) {
        var canvas = resolveElement(canvasRef);
        if (!canvas) {
          return Promise.resolve(null);
        }

        var chartKey = key + ':' + (canvas.id || canvas.getAttribute('data-chart-key') || 'chart');
        var settings = options || {};
        var emptyMessage = settings.emptyMessage || 'No chart data for the selected filters.';

        if (!config || !dataHasContent(config)) {
          destroyChart(chartKey);
          setEmptyState(canvas, true, emptyMessage);
          return Promise.resolve(null);
        }

        return ensureChartJs()
          .then(function () {
            destroyChart(chartKey);
            setEmptyState(canvas, false, emptyMessage);
            charts[chartKey] = new window.Chart(canvas, config);
            return charts[chartKey];
          })
          .catch(function () {
            setEmptyState(canvas, true, 'Chart library failed to load.');
            return null;
          });
      },
      destroyAll: function () {
        Object.keys(charts).forEach(function (chartKey) {
          destroyChart(chartKey);
        });
      }
    };
  }

  function parsePayload(root) {
    var host = resolveElement(root);
    if (!host) {
      return {};
    }
    var payloadNode = host.querySelector('script[type="application/json"][data-chart-payload]');
    if (!payloadNode) {
      return {};
    }
    try {
      return JSON.parse(payloadNode.textContent || '{}');
    } catch (error) {
      return {};
    }
  }

  function toggleLoading(target, isLoading) {
    if (!target) {
      return;
    }
    target.classList.toggle('gac-report-loading', !!isLoading);
  }

  function bindAjaxForm(config) {
    var form = resolveElement(config && config.form);
    var target = resolveElement(config && config.target);
    if (!form || !target) {
      return;
    }
    if (form.getAttribute('data-gac-report-ajax-bound') === '1') {
      return;
    }
    form.setAttribute('data-gac-report-ajax-bound', '1');

    var mode = (config && config.mode) || 'partial';
    var sourceSelector = (config && config.sourceSelector) || '';
    var updateHistory = !(config && config.updateHistory === false);

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var params = new URLSearchParams(new FormData(form));
      if (config && typeof config.extendParams === 'function') {
        config.extendParams(params);
      }

      var formAction = form.getAttribute('action') || window.location.pathname;
      var requestUrl = formAction + '?' + params.toString();
      toggleLoading(target, true);

      fetch(requestUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (response) {
          return response.text();
        })
        .then(function (text) {
          if (mode === 'full') {
            var parser = new window.DOMParser();
            var doc = parser.parseFromString(text, 'text/html');
            var source = doc.querySelector(sourceSelector || ('#' + target.id));
            if (!source) {
              throw new Error('Unable to locate response fragment.');
            }
            target.innerHTML = source.innerHTML;
          } else {
            target.innerHTML = text;
          }

          if (updateHistory) {
            var historyParams = new URLSearchParams(new FormData(form));
            var historyUrl = formAction + '?' + historyParams.toString();
            window.history.replaceState({}, '', historyUrl);
          }

          if (config && typeof config.afterUpdate === 'function') {
            config.afterUpdate(target);
          }
        })
        .catch(function () {
          target.innerHTML = '<div class="alert alert-danger">Unable to refresh report data. Please retry.</div>';
        })
        .finally(function () {
          toggleLoading(target, false);
        });
    });
  }

  function commonOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false
      },
      plugins: {
        legend: {
          position: 'bottom'
        }
      }
    };
  }

  function asCurrency(value) {
    var amount = Number(value || 0);
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency: 'INR',
      maximumFractionDigits: 0
    }).format(amount);
  }

  window.GacCharts = {
    palette: palette,
    pickColors: pickColors,
    createRegistry: createRegistry,
    parsePayload: parsePayload,
    bindAjaxForm: bindAjaxForm,
    commonOptions: commonOptions,
    asCurrency: asCurrency,
    ensureChartJs: ensureChartJs
  };
})(window, document);
