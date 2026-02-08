document.addEventListener('DOMContentLoaded', function () {
  const dangerousForms = document.querySelectorAll('form[data-confirm]');
  dangerousForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      const message = form.getAttribute('data-confirm') || 'Are you sure?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });
});
