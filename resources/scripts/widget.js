// The `altcha` package's entry registers the <altcha-widget> custom element
// as a side-effect. That's all we need — the PHP side renders the markup,
// the widget auto-solves the proof on load, and Gravity Forms posts the
// resulting hidden input back to the server like any other field.
import 'altcha';
