define([
    'M2ePro/Plugin/Messages',
    'M2ePro/Listing/View/Grid',
    'M2ePro/Listing/Moving',
    'M2ePro/Listing/Mapping',
    'M2ePro/Walmart/Listing/View/Action',
    'M2ePro/Walmart/Listing/Product/ProductType',
    'M2ePro/Walmart/Listing/Product/Variation/Manage',
    'M2ePro/Walmart/Listing/Product/EditChannelData'
], function (MessageObj) {

    window.WalmartListingViewGrid = Class.create(ListingViewGrid, {

        MessageObj: null,

        // ---------------------------------------

        getLogViewUrl: function (rowId) {
            var idField = M2ePro.php.constant('\\Ess\\M2ePro\\Block\\Adminhtml\\Log\\Listing\\Product\\AbstractGrid::LISTING_PRODUCT_ID_FIELD');

            var params = {};
            params[idField] = rowId;

            return M2ePro.url.get('walmart_log_listing_product/index', params);
        },

        // ---------------------------------------

        getMaxProductsInPart: function()
        {
            return 10;
        },

        // ---------------------------------------

        prepareActions: function($super)
        {
            this.MessageObj = MessageObj;

            this.actionHandler = new WalmartListingViewAction(this);

            this.actions = {
                listAction: this.actionHandler.listAction.bind(this.actionHandler),
                relistAction: this.actionHandler.relistAction.bind(this.actionHandler),
                reviseAction: this.actionHandler.reviseAction.bind(this.actionHandler),
                stopAction: this.actionHandler.stopAction.bind(this.actionHandler),
                stopAndRemoveAction: this.actionHandler.stopAndRemoveAction.bind(this.actionHandler),
                previewItemsAction: this.actionHandler.previewItemsAction.bind(this.actionHandler)
            };

            this.movingHandler = new ListingMoving(this);
            this.mappingHandler = new ListingMapping(this, 'walmart');

            this.productTypeHandler = new WalmartListingProductProductType(this);

            this.variationProductManageHandler = new WalmartListingProductVariationManage(this);
            this.editChannelDataHandler = new WalmartListingProductEditChannelData(this);

            this.actions = Object.extend(this.actions, {
                duplicateAction: this.duplicateProducts.bind(this),
                movingAction: this.movingHandler.run.bind(this.movingHandler),
                deleteAndRemoveAction: this.actionHandler.deleteAndRemoveAction.bind(this.actionHandler),

                changeProductTypeAction: (function(id) {
                    id = id || this.getSelectedProductsString();
                    this.productTypeHandler.validateProductsForProductTypeAssign(id, null)
                }).bind(this),

                unassignProductTypeAction: (function (id) {
                    id = id || this.getSelectedProductsString();
                    this.confirm({
                        actions: {
                            confirm: () => this.productTypeHandler.unassign(id),
                            cancel: () => false
                        }
                    });
                }).bind(this),

                remapProductAction: function(id) {
                    this.mappingHandler.openPopUp(id, null, this.listingId);
                }.bind(this)
            });
        },

        // ---------------------------------------

        tryToMove: function (listingId) {
            this.movingHandler.submit(listingId, this.onSuccess);
        },

        onSuccess: function () {
            this.unselectAllAndReload();
        },

        // ---------------------------------------

        duplicateProducts: function () {
            this.scrollPageToTop();
            MessageObj.clear();

            new Ajax.Request(M2ePro.url.get('walmart_listing/duplicateProducts'), {
                method: 'post',
                parameters: {
                    ids: this.getSelectedProductsString()
                },
                onSuccess: (function (transport) {

                    try {
                        var response = transport.responseText.evalJSON();

                        MessageObj['add' + response.type[0].toUpperCase() + response.type.slice(1)](response.message);

                        if (response.type != 'error') {
                            this.unselectAllAndReload();
                        }

                    } catch (e) {
                        MessageObj.addError('Internal Error.');
                    }
                }).bind(this)
            });
        },

        // ---------------------------------------
    });

});
