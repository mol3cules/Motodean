# Predictive Analytics Module

This module provides machine learning-powered demand forecasting and stock management capabilities for the MotoDean e-commerce system.

## Files

- `demand_forecast_api.php` - API endpoint for ML predictions
- `process_sales_ml.py` - Python ML analysis script
- `sales_dataset-final.csv` - Historical sales data
- `ML_INTEGRATION_GUIDE.md` - Detailed integration guide

## Quick Start

1. Ensure Python dependencies are installed:
   ```bash
   pip install pandas numpy scikit-learn matplotlib
   ```

2. Access the analytics dashboard at `admin/analytics.php`
3. Click "Load Predictive Analytics" to generate forecasts
4. Use the products page at `admin/products.php` for stock recommendations

## Features

- **Demand Forecasting**: 3-month demand predictions
- **Stock Alerts**: Priority-based stock level warnings
- **ML Accuracy**: Model performance metrics
- **Top Products**: AI-identified best performers
- **Seasonal Analysis**: Time-based demand adjustments

## Support

Refer to `ML_INTEGRATION_GUIDE.md` for detailed documentation and troubleshooting.