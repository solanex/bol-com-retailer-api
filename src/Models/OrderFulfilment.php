<?php

namespace HarmSmits\BolComClient\Models;

/**
 * @method null|string getMethod()
 * @method self setMethod(string $method)
 * @method null|string getDistributionParty()
 * @method null|string getLatestDeliveryDate()
 * @method self setLatestDeliveryDate(string $latestDeliveryDate)
 * @method null|string getExactDeliveryDate()
 * @method self setExactDeliveryDate(string $exactDeliveryDate)
 * @method null|string getExpiryDate()
 * @method self setExpiryDate(string $expiryDate)
 */
final class OrderFulfilment extends AModel
{
    const DISTRIBUTION_PARTY_RETAILER = 'RETAILER';
    const DISTRIBUTION_PARTY_BOL = 'BOL';

    /**
     * Specifies whether this shipment has been fulfilled by the retailer (FBR) or fulfilled by bol.com (FBB).
     * Defaults to FBR.
     * @var string
     */
    protected ?string $method = null;

    /**
     * The party that manages the distribution, either bol.com or the retailer itself.
     * @var string
     */
    protected ?string $distributionParty = null;

    /**
     * The ultimate delivery date at which this order must be delivered at the customer's shipping address. This
     * field is empty in case the exactDeliveryDate is filled.
     * @var string
     */
    protected ?string $latestDeliveryDate = null;

    /**
     * The exact delivery date at which this order must be delivered at the customer's shipping address. This field
     * is only filled when the customer chose an exact date for delivery. This field is empty in case the
     * latestDeliveryDate is filled.
     * @var string
     */
    protected ?string $exactDeliveryDate = null;

    /**
     * The date this order item will automatically expire and thereby cancelling this order item from the order.
     * @var string
     */
    protected ?string $expiryDate = null;


    /**
     * The timeFrameType field in Get order indicates which delivery time option was selected by the customer during the checkout.
     * @var string
     */
    protected ?string $timeFrameType = null;

    public function setDistributionParty(string $distributionParty): self
    {
        $this->_checkEnumBounds($distributionParty, [
            "RETAILER",
            "BOL",
        ]);
        $this->distributionParty = $distributionParty;
        return $this;
    }
}
