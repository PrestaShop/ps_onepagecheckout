const OPC_OPTION_LIST_STATE = {
  LOADING: 'loading',
  AVAILABLE: 'available',
  EMPTY: 'empty',
  FAILED: 'failed',
  // The section cannot show its options yet because no usable delivery/tax address exists; it shows
  // the awaiting-address hint (which lists the still-missing required fields) instead of fetching.
  AWAITING_ADDRESS: 'awaiting-address',
};

export default OPC_OPTION_LIST_STATE;
