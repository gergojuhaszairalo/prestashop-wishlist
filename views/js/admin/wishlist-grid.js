/**
 * Core grid extensions for the read-only wishlist grid (see example-modules/demo_grid).
 */
$(() => {
  const grid = new window.prestashop.component.Grid('summitwishlist');
  grid.addExtension(new window.prestashop.component.GridExtensions.SortingExtension());
  grid.addExtension(new window.prestashop.component.GridExtensions.FiltersResetExtension());
  grid.addExtension(new window.prestashop.component.GridExtensions.FiltersSubmitButtonEnablerExtension());
  grid.addExtension(new window.prestashop.component.GridExtensions.LinkRowActionExtension());
});
