# Machine Learning Integration Guide

## Overview
This guide explains the integration of predictive analytics and machine learning capabilities into the MotoDean e-commerce system for demand forecasting and stock management.

## Features Implemented

### 1. Demand Forecasting API (`demand_forecast_api.php`)
- **Purpose**: Serves ML predictions for demand forecasting and stock management
- **Endpoints**:
  - `GET ?action=forecast` - Get demand forecasts for all products
  - `GET ?action=alerts` - Get stock alerts based on forecasts
- **Data Sources**: 
  - Historical sales data from `sales_dataset-final.csv`
  - Current product data from database
  - ML model results from `process_sales_ml.py`

### 2. Analytics Dashboard Integration
- **Location**: `admin/analytics.php`
- **Features**:
  - ML Model Accuracy display
  - Stock Alerts & Recommendations
  - Top Performing Products analysis
  - Demand forecasting with confidence levels

### 3. Products Management Integration
- **Location**: `admin/products.php`
- **Features**:
  - AI Stock Recommendations section
  - Priority-based stock alerts (Critical, High, Medium)
  - Detailed reorder recommendations
  - Top performing products analysis

## ML Model Details

### Logistic Regression Implementation
The system uses logistic regression for demand forecasting with the following features:

1. **Training Data**: `sales_dataset-final.csv`
2. **Model Accuracy**: Displayed in analytics dashboard
3. **Predictions**: 3-month demand forecasts for each product
4. **Confidence Levels**: Based on historical data availability

### Seasonal Adjustments
- **January**: 0.8 (post-holiday dip)
- **February**: 0.7 (low season)
- **March**: 0.9 (spring pickup)
- **April**: 1.0 (normal)
- **May**: 1.1 (spring peak)
- **June**: 1.0 (normal)
- **July**: 0.9 (summer dip)
- **August**: 0.8 (summer low)
- **September**: 1.0 (back to school)
- **October**: 1.1 (pre-holiday)
- **November**: 1.2 (holiday buildup)
- **December**: 1.3 (holiday peak)

### Trend Analysis
- **Top Performers**: 20% increase factor for products in ML top 10
- **Normal Products**: 1.0 trend factor
- **Confidence Calculation**: Based on historical data points

## Stock Management Features

### Stock Status Classification
1. **Out of Stock** (Critical Priority)
   - Current stock = 0
   - Immediate reorder recommended
   - Red alert color

2. **Low Stock** (High Priority)
   - Current stock < 30% of predicted demand
   - Reorder recommended
   - Yellow alert color

3. **Moderate Stock** (Medium Priority)
   - Current stock < 50% of predicted demand
   - Monitor closely
   - Blue alert color

4. **Adequate Stock** (Normal)
   - Current stock >= 50% of predicted demand
   - No action needed
   - Green status

### Reorder Recommendations
- **Out of Stock**: Reorder quantity = max(50, total_predicted_demand)
- **Low Stock**: Reorder quantity = max(30, total_predicted_demand - current_stock)
- **Moderate Stock**: Monitor stock levels

## Usage Instructions

### For Analytics Dashboard
1. Navigate to `admin/analytics.php`
2. Scroll to "Demand Forecasting & Stock Alerts" section
3. Click "Load Predictive Analytics" button
4. View ML accuracy, stock alerts, and top products

### For Products Management
1. Navigate to `admin/products.php`
2. Scroll to "AI Stock Recommendations" section
3. Click "Generate Recommendations" button
4. Review priority-based recommendations and reorder suggestions

## Technical Requirements

### Python Dependencies
```bash
pip install pandas numpy scikit-learn matplotlib
```

### File Structure
```
admin/predictive-analytics/
├── demand_forecast_api.php      # API endpoint
├── process_sales_ml.py         # ML analysis script
├── sales_dataset-final.csv     # Historical data
├── output/                     # ML results cache
└── ML_INTEGRATION_GUIDE.md     # This guide
```

### Database Integration
- Uses existing `products` table for current stock levels
- Integrates with `orders` table for sales history
- No additional database tables required

## Performance Considerations

### Caching
- ML results are cached for 1 hour to improve performance
- API responses include timestamp for cache validation

### Error Handling
- Graceful fallback when ML analysis fails
- User-friendly error messages
- Console logging for debugging

### Scalability
- Batch processing for large product catalogs
- Efficient CSV data processing
- Optimized database queries

## Future Enhancements

### Planned Features
1. **Real-time Alerts**: Email notifications for critical stock levels
2. **Advanced ML Models**: Random Forest, Neural Networks
3. **Seasonal Analysis**: More sophisticated seasonal patterns
4. **Supplier Integration**: Automatic reorder requests
5. **Price Optimization**: ML-based pricing recommendations

### Data Expansion
- Customer behavior analysis
- Market trend integration
- Competitor pricing data
- Weather and event correlation

## Troubleshooting

### Common Issues
1. **Python Module Not Found**: Install required dependencies
2. **CSV File Not Found**: Ensure `sales_dataset-final.csv` exists
3. **Permission Errors**: Check file permissions for output directory
4. **Memory Issues**: Optimize for large datasets

### Debug Mode
- Enable console logging in JavaScript
- Check PHP error logs
- Monitor Python script output
- Validate API responses

## Support
For technical support or feature requests, contact the development team or refer to the system documentation.