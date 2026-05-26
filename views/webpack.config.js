/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */

const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: {
    'opc-submit': './js/opc-submit.js',
    'opc-guest-init': './js/opc-guest-init.js',
    'opc-address': './js/opc-address.js',
    'opc-address-modal': './js/opc-address-modal.js',
    'opc-carrier-list': './js/opc-carrier-list.js',
    'opc-carrier-select': './js/opc-carrier-select.js',
    'opc-payment-list': './js/opc-payment-list.js',
    'opc-payment-select': './js/opc-payment-select.js',
    'opc-gift-wrapping': './js/opc-gift-wrapping.js',
    'opc-checkout-layout': './js/opc-checkout-layout.js',
    'opc-cart-summary-state': './js/opc-cart-summary-state.js',
    'one-page-checkout': './scss/one-page-checkout.scss',
  },
  output: {
    path: path.resolve(__dirname, 'public'),
    filename: '[name].bundle.js',
  },
  externals: {
    jquery: 'jQuery',
    prestashop: 'prestashop',
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env'],
          },
        },
      },
      {
        test: /\.scss$/,
        use: [
          MiniCssExtractPlugin.loader,
          {
            loader: 'css-loader',
            options: {url: false},
          },
          {
            loader: 'sass-loader',
            options: {
              sassOptions: {
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions'],
              },
            },
          },
        ],
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({filename: '[name].css'}),
  ],
  optimization: {
    minimize: process.env.NODE_ENV === 'production',
    minimizer: [new TerserPlugin()],
  },
};
